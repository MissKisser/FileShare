/**
 * 分片上传控制器。
 */
(function (global) {
    'use strict';

    var DEFAULT_CHUNK_SIZE = 5 * 1024 * 1024;
    var DEFAULT_CONCURRENCY = 3;
    var CHUNK_THRESHOLD = 50 * 1024 * 1024;

    function FileShareChunkedUploader(options) {
        this.chunkSize = (options && options.chunkSize) || DEFAULT_CHUNK_SIZE;
        this.concurrency = (options && options.concurrency) || DEFAULT_CONCURRENCY;
        this.onProgress = (options && options.onProgress) || function () {};
        this.onSuccess = (options && options.onSuccess) || function () {};
        this.onError = (options && options.onError) || function () {};
        this._aborted = false;
    }

    /**
     * 判断文件是否应该使用分片上传
     */
    FileShareChunkedUploader.shouldUseChunkedUpload = function (file) {
        return file.size > CHUNK_THRESHOLD;
    };

    /**
     * 上传文件（分片模式）
     */
    FileShareChunkedUploader.prototype.upload = function (file, meta) {
        this._aborted = false;
        return this._uploadChunked(file, meta || {});
    };

    /**
     * 中止上传
     */
    FileShareChunkedUploader.prototype.abort = function () {
        this._aborted = true;
    };

    FileShareChunkedUploader.prototype._uploadChunked = function (file, meta) {
        var self = this;
        var totalChunks = Math.ceil(file.size / this.chunkSize);
        var sessionId = this._generateSessionId();
        // I7：保存 largeFilePassword 供 merge 阶段重新校验（防 init 后绕过）
        var largeFilePassword = meta.largeFilePassword || '';

        // 步骤 1：init
        return this._apiCall('upload/init', {
            session_id: sessionId,
            filename: file.name,
            size: file.size,
            mime: file.type || 'application/octet-stream',
            chunk_size: this.chunkSize,
            total_chunks: totalChunks,
            duration: meta.duration || 600,
            access_password: meta.accessPassword || '',
            large_file_password: largeFilePassword,
        }).then(function (initResp) {
            if (self._aborted) throw new Error('上传已取消');

            var received = initResp.received_chunks || '';
            var actualSessionId = initResp.session_id || sessionId;

            // 步骤 2：收集待上传分片
            var pending = [];
            for (var i = 0; i < totalChunks; i++) {
                if (received[i] !== '1') {
                    pending.push(i);
                }
            }

            var completed = totalChunks - pending.length;
            var totalSize = file.size;
            var uploadedBytes = completed * self.chunkSize;

            // 步骤 3：并发上传分片
            return self._runConcurrent(pending, self.concurrency, function (chunkIdx) {
                if (self._aborted) throw new Error('上传已取消');

                var start = chunkIdx * self.chunkSize;
                var end = Math.min(start + self.chunkSize, file.size);
                var blob = file.slice(start, end);

                var fd = new FormData();
                fd.append('session_id', actualSessionId);
                fd.append('chunk_index', chunkIdx);
                if (window.FILESHARE_CSRF) {
                    fd.append('csrf_token', window.FILESHARE_CSRF);
                }
                fd.append('file', blob, file.name + '.part' + chunkIdx);

                return new Promise(function (resolve, reject) {
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '?api=upload/chunk', true);
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                    xhr.onload = function () {
                        if (xhr.status === 200) {
                            uploadedBytes += end - start;
                            completed++;
                            var pct = Math.min(99, Math.round((uploadedBytes / totalSize) * 100));
                            self.onProgress(pct);
                            resolve();
                        } else {
                            reject(new Error('分片 ' + chunkIdx + ' 上传失败：HTTP ' + xhr.status));
                        }
                    };
                    xhr.onerror = function () {
                        reject(new Error('分片 ' + chunkIdx + ' 网络错误'));
                    };
                    xhr.send(fd);
                });
            }).then(function () {
                if (self._aborted) throw new Error('上传已取消');

                // 步骤 4：merge（I7：merge 阶段重新校验大文件密码，body 必须带）
                return self._apiCall('upload/merge', {
                    session_id: actualSessionId,
                    large_file_password: largeFilePassword,
                });
            }).then(function (mergeResp) {
                if (mergeResp.success && mergeResp.item) {
                    self.onProgress(100);
                    self.onSuccess(mergeResp.item);
                } else {
                    self.onError(mergeResp.message || '合并失败');
                }
            });
        }).catch(function (err) {
            if (self._aborted) {
                self.onError('上传已取消');
            } else {
                self.onError(err.message || '上传异常');
            }
        });
    };

    FileShareChunkedUploader.prototype._generateSessionId = function () {
        var arr = new Uint8Array(16);
        crypto.getRandomValues(arr);
        var hex = '';
        for (var i = 0; i < arr.length; i++) {
            hex += arr[i].toString(16).padStart(2, '0');
        }
        return hex;
    };

    FileShareChunkedUploader.prototype._runConcurrent = function (items, limit, worker) {
        var results = [];
        var executing = [];

        function chain(item) {
            var p = Promise.resolve().then(function () { return worker(item); });
            results.push(p);
            executing.push(p);
            p.then(function () {
                var idx = executing.indexOf(p);
                if (idx !== -1) executing.splice(idx, 1);
            }, function () {
                var idx = executing.indexOf(p);
                if (idx !== -1) executing.splice(idx, 1);
            });
            return p;
        }

        var self = this;
        var index = 0;

        function next() {
            if (self._aborted) return Promise.resolve();
            if (index >= items.length) return Promise.resolve();
            var p = chain(items[index++]);
            if (executing.length >= limit) {
                return Promise.race(executing).then(next);
            }
            return next();
        }

        next();
        return Promise.allSettled(results);
    };

    FileShareChunkedUploader.prototype._apiCall = function (endpoint, body) {
        var payload = Object.assign({}, body);
        if (window.FILESHARE_CSRF) {
            payload.csrf_token = window.FILESHARE_CSRF;
        }
        return fetch('?api=' + endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        }).then(function (resp) {
            return resp.json().then(function (data) {
                if (!resp.ok || data.error) {
                    throw new Error(data.error || data.message || 'API 错误');
                }
                return data;
            });
        });
    };

    global.FileShareChunkedUploader = FileShareChunkedUploader;
})(window);
