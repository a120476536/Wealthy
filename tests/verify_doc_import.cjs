const { chromium } = require('playwright');
const fs = require('fs');

const BASE = 'http://127.0.0.1:8791';
const DOCX = '/tmp/doc_import_test.docx';
const FRONT_USER = process.env.F_USER || '程序员老K';
const FRONT_PASS = process.env.F_PASS || 'abc123456';

const CHROME_CANDIDATES = [
  process.env.CHROME_BIN,
  '/Users/ljj/Library/Caches/ms-playwright/chromium-1248/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing',
  '/Users/ljj/Library/Caches/ms-playwright/chromium-1208/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing',
].filter(Boolean);
const EXEC = CHROME_CANDIDATES.find((p) => fs.existsSync(p));
const launchOpts = { headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] };
if (EXEC) launchOpts.executablePath = EXEC;
const PROXY = process.env.http_proxy || process.env.HTTP_PROXY || process.env.https_proxy || process.env.HTTPS_PROXY;
if (PROXY) launchOpts.proxy = { server: PROXY, bypass: '127.0.0.1,localhost' };

(async () => {
  const browser = await chromium.launch(launchOpts);
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  const consoleErrors = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
  page.on('pageerror', (e) => consoleErrors.push('PAGEERROR: ' + e.message));

  // 前台登录
  await page.goto(BASE + '/login', { waitUntil: 'load' });
  await page.fill('input[name=username]', FRONT_USER);
  await page.fill('input[name=password]', FRONT_PASS);
  await page.click('#loginForm button[type=submit]');
  await page.waitForFunction(() => document.cookie.includes('wa_auth'), { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(800);

  // 打开编辑器
  const resp = await page.goto(BASE + '/post/create', { waitUntil: 'load', timeout: 30000 });
  console.log('PAGE /post/create ->', resp && resp.status());

  // 等待 CDN 库加载
  let mammothOk = true, pdfOk = true;
  try { await page.waitForFunction(() => typeof window.mammoth !== 'undefined', { timeout: 20000 }); }
  catch (e) { mammothOk = false; }
  try { await page.waitForFunction(() => typeof window.pdfjsLib !== 'undefined', { timeout: 20000 }); }
  catch (e) { pdfOk = false; }

  const dom = await page.evaluate(() => ({
    modal: !!document.getElementById('docModal'),
    btn: !!document.getElementById('docImportBtn'),
    openFn: typeof window.openDocModal,
    pdfScript: !!document.querySelector('script[src*="pdfjs-dist"]'),
    mammothScript: !!document.querySelector('script[src*="mammoth"]'),
  }));

  // 选文件 → 导入（docx + pdf 两轮）
  async function runImport(file, expectText) {
    await page.goto(BASE + '/post/create', { waitUntil: 'load', timeout: 30000 });
    await page.evaluate(() => openDocModal());
    await page.waitForSelector('#docModal:not(.hidden)', { timeout: 5000 });
    await page.setInputFiles('#docFile', file);
    await page.click('#docImportBtn');
    try {
      await page.waitForFunction((txt) => {
        const t = document.getElementById('title');
        const ed = (window.editor && typeof window.editor.getHtml === 'function') ? window.editor.getHtml() : '';
        return (t && t.value) || ed.indexOf(txt) >= 0;
      }, expectText, { timeout: 25000 });
      const after = await page.evaluate(() => ({
        title: document.getElementById('title').value,
        html: (window.editor && typeof window.editor.getHtml === 'function') ? window.editor.getHtml() : '',
      }));
      return { ok: true, title: after.title, hasText: after.html.indexOf(expectText) >= 0 };
    } catch (e) {
      return { ok: false, error: e.message };
    }
  }

  const docxResult = await runImport(DOCX, '我的博文标题');
  const pdfResult = await runImport('/tmp/doc_import_test.pdf', 'ceShi');

  console.log(JSON.stringify({
    mammothLoaded: mammothOk,
    pdfLoaded: pdfOk,
    dom,
    docxResult,
    pdfResult,
    consoleErrors: consoleErrors.slice(0, 10),
  }, null, 2));

  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
