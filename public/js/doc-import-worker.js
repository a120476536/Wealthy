/* docx → HTML 后台解析线程（Web Worker）
 * 作用：mammoth 解析大 .docx 是重 CPU 同步操作，放主线程会导致整页“无响应”。
 * 这里在独立线程中运行 mammoth，主线程仅收发消息，UI 全程可响应。
 * 依赖：/vendor/mammoth.browser.min.js（同源，importScripts 可加载）。
 */
importScripts('/vendor/mammoth.browser.min.js');

self.onmessage = function (e) {
  var buf = e.data && e.data.arrayBuffer;
  if (!buf) { self.postMessage({ type: 'error', message: 'missing arrayBuffer' }); return; }
  try {
    mammoth.convertToHtml({ arrayBuffer: buf }).then(function (res) {
      self.postMessage({ type: 'html', html: res.value || '' });
    }).catch(function (err) {
      self.postMessage({ type: 'error', message: (err && err.message) ? err.message : String(err) });
    });
  } catch (err) {
    self.postMessage({ type: 'error', message: (err && err.message) ? err.message : String(err) });
  }
};
