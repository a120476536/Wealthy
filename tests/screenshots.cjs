/**
 * 造富计划 (Wealthy) 功能模块截图脚本
 * 依赖：node workspace 下的 playwright（已下载 chromium）
 * 运行：NODE_PATH=/Users/ljj/.workbuddy/binaries/node/workspace/node_modules \
 *       /Users/ljj/.workbuddy/binaries/node/versions/22.22.2-3/bin/node tests/screenshots.cjs
 *
 * 说明：
 *  - 本地服务假设运行在 http://127.0.0.1:8791
 *  - 通过环境代理放行外网（Tailwind CDN），本地 8791 直连
 *  - 前台以 程序员老K / abc123456 登录；后台以 admin / admin888 登录
 */
const { chromium } = require('playwright');
const path = require('path');

const BASE = 'http://127.0.0.1:8791';
const OUT = '/Users/ljj/Documents/gitProject/phpProject/Wealthy/assets';

const FRONT_USER = process.env.F_USER || '程序员老K';
const FRONT_PASS = process.env.F_PASS || 'abc123456';
const ADMIN_USER = process.env.A_USER || 'admin';
const ADMIN_PASS = process.env.A_PASS || 'admin888';

// 浏览器可执行文件：优先用 Playwright 自带 chromium，否则回退到本机已有的
// Google Chrome for Testing (chromium-1208)，避免重新下载浏览器二进制。
const CHROME_CANDIDATES = [
  process.env.CHROME_BIN,
  '/Users/ljj/Library/Caches/ms-playwright/chromium-1248/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing',
  '/Users/ljj/Library/Caches/ms-playwright/chromium-1208/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing',
].filter(Boolean);
const fs = require('fs');
const EXEC = CHROME_CANDIDATES.find((p) => fs.existsSync(p));

const launchOpts = { headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] };
if (EXEC) launchOpts.executablePath = EXEC;

const results = [];
async function snap(page, file, url, { full = true, wait = 1500 } = {}) {
  try {
    await page.goto(BASE + url, { waitUntil: 'load', timeout: 30000 });
    await page.waitForTimeout(wait);
    await page.screenshot({ path: path.join(OUT, file), fullPage: full });
    results.push(['OK', file, url]);
    console.log('OK  ', file, url);
  } catch (e) {
    results.push(['FAIL', file, url, e.message]);
    console.log('FAIL', file, url, '->', e.message);
  }
}

async function frontLogin(page) {
  await page.goto(BASE + '/login', { waitUntil: 'load' });
  await page.fill('input[name=username]', FRONT_USER);
  await page.fill('input[name=password]', FRONT_PASS);
  await page.click('#loginForm button[type=submit]');
  await page.waitForFunction(() => document.cookie.includes('wa_auth'), { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(1000);
}

async function adminLogin(page) {
  await page.goto(BASE + '/admin/login', { waitUntil: 'load' });
  await page.fill('#username', ADMIN_USER);
  await page.fill('#password', ADMIN_PASS);
  await page.click('#loginForm button');
  await page.waitForFunction(() => document.cookie.includes('wa_admin_auth'), { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(1000);
}

(async () => {
  const browser = await chromium.launch(launchOpts);
  const ctxOpts = { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 };

  // ============ 前台（Frontend）============
  const fctx = await browser.newContext(ctxOpts);
  const fpage = await fctx.newPage();

  // 公开页面（登录前）
  await snap(fpage, '01-login.png', '/login');
  await snap(fpage, '02-register.png', '/register');
  await snap(fpage, '03-home.png', '/');
  await snap(fpage, '04-posts.png', '/posts');
  await snap(fpage, '05-post-detail.png', '/post/705');
  await snap(fpage, '06-user-home.png', '/u/4');
  await snap(fpage, '07-search.png', '/search?kw=%E6%B5%8B%E8%AF%95');
  await snap(fpage, '08-help-merchant-qr.png', '/help/merchant-qr');
  await snap(fpage, '09-help-agreement.png', '/help/user-agreement');

  // 登录后
  await frontLogin(fpage);
  await snap(fpage, '10-profile.png', '/profile');
  await snap(fpage, '11-profile-received.png', '/profile/received');
  await snap(fpage, '12-profile-posts.png', '/profile/posts');
  await snap(fpage, '13-profile-messages.png', '/profile/message-sessions');
  await snap(fpage, '14-profile-invite.png', '/profile/invite');
  await snap(fpage, '15-profile-points.png', '/profile/points-logs');

  // ============ 后台（Admin）============
  const actx = await browser.newContext(ctxOpts);
  const apage = await actx.newPage();

  // 登录页（公开）
  await snap(apage, '16-admin-login.png', '/admin/login');

  // 登录后
  await adminLogin(apage);
  await snap(apage, '17-admin-dashboard.png', '/admin/');
  await snap(apage, '18-admin-users.png', '/admin/users');
  await snap(apage, '19-admin-qr-audit.png', '/admin/qr-audit');
  await snap(apage, '20-admin-tips.png', '/admin/tips');
  await snap(apage, '21-admin-disputes.png', '/admin/disputes');
  await snap(apage, '22-admin-posts.png', '/admin/posts');
  await snap(apage, '23-admin-post-category.png', '/admin/post-category');
  await snap(apage, '24-admin-comments.png', '/admin/comments');
  await snap(apage, '25-admin-sensitive-words.png', '/admin/sensitive-words');
  await snap(apage, '26-admin-ads.png', '/admin/ads');
  await snap(apage, '27-admin-risk-monitor.png', '/admin/risk-monitor');
  await snap(apage, '28-admin-system-setting.png', '/admin/system-setting');
  await snap(apage, '29-admin-invite-codes.png', '/admin/invite-codes');
  await snap(apage, '30-admin-admins.png', '/admin/admins');
  await snap(apage, '31-admin-reports.png', '/admin/reports');
  await snap(apage, '32-admin-notifications.png', '/admin/notifications');
  await snap(apage, '33-admin-notification-records.png', '/admin/notification-records');
  await snap(apage, '34-admin-logs.png', '/admin/admin-logs');

  await browser.close();

  const fails = results.filter((r) => r[0] === 'FAIL');
  console.log('\n==== 完成：' + results.length + ' 张，失败 ' + fails.length + ' 张 ====');
  if (fails.length) {
    console.log('失败列表：');
    fails.forEach((f) => console.log(' - ' + f[1] + ' ' + f[2] + ' :: ' + f[3]));
  }
})().catch((e) => {
  console.error('SCRIPT ERROR', e);
  process.exit(1);
});
