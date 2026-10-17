<?php
/**
 * 造富计划 - 演示数据种子
 * 用法：php database/seed.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

// 连接参数支持环境变量覆盖（install.php 安装时注入），默认值保持原样
$dbHost = getenv('WF_DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('WF_DB_PORT') ?: '3306';
$dbName = getenv('WF_DB_NAME') ?: 'wealthy';
$dbUser = getenv('WF_DB_USER') ?: 'root';
$dbPass = getenv('WF_DB_PASS') ?: 'root';
$pdo = new PDO(
    "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
    $dbUser, $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "=> connect ok ({$dbHost}:{$dbPort}/{$dbName})\n";

function ensureDir(string $d): void { if (!is_dir($d)) mkdir($d, 0777, true); }
function makeAvatar(string $path, string $name, string $bg): void {
    $s = 200; $im = imagecreatetruecolor($s, $s);
    [$r,$g,$b] = sscanf($bg, "#%02x%02x%02x");
    $bgc = imagecolorallocate($im, $r, $g, $b); $wht = imagecolorallocate($im, 255, 255, 255);
    imagefill($im, 0, 0, $bgc);
    $ch = mb_substr($name, 0, 1); $font = 5;
    $tw = imagefontwidth($font) * mb_strwidth($ch); $th = imagefontheight($font);
    imagestring($im, $font, (int)(($s-$tw)/2), (int)(($s-$th)/2), $ch, $wht);
    imagepng($im, $path); imagedestroy($im);
}
function makeQr(string $path, string $label, string $bg): void {
    $s = 320; $im = imagecreatetruecolor($s, $s);
    $wht = imagecolorallocate($im,255,255,255); $blk = imagecolorallocate($im,20,20,20);
    [$r,$g,$b] = sscanf($bg, "#%02x%02x%02x"); $accent = imagecolorallocate($im,$r,$g,$b);
    imagefill($im,0,0,$wht); $cell = 10; $n = intdiv($s-40,$cell); mt_srand(crc32($label));
    for ($i=0;$i<$n;$i++) for ($j=0;$j<$n;$j++) if (mt_rand(0,1))
        imagefilledrectangle($im,20+$i*$cell,20+$j*$cell,20+$i*$cell+$cell-1,20+$j*$cell+$cell-1,$blk);
    foreach ([[20,20],[20,$s-60],[$s-60,20]] as [$x,$y]) {
        imagefilledrectangle($im,$x,$y,$x+40,$y+40,$blk);
        imagefilledrectangle($im,$x+8,$y+8,$x+32,$y+32,$wht);
        imagefilledrectangle($im,$x+14,$y+14,$x+26,$y+26,$blk);
    }
    imagefilledrectangle($im,0,$s-26,$s,$s,$accent);
    $tw = imagefontwidth(5)*mb_strwidth($label);
    imagestring($im,5,(int)(($s-$tw)/2),$s-20,$label,$wht);
    imagepng($im,$path); imagedestroy($im);
}
function makeAd(string $path, string $title, string $bg): void {
    $w=728;$h=90; $im=imagecreatetruecolor($w,$h);
    [$r,$g,$b]=sscanf($bg,"#%02x%02x%02x"); $bgc=imagecolorallocate($im,$r,$g,$b);
    $wht=imagecolorallocate($im,255,255,255); $yel=imagecolorallocate($im,255,209,102);
    imagefill($im,0,0,$bgc); imagefilledrectangle($im,16,22,22,68,$yel);
    imagestring($im,5,36,24,$title,$wht); imagestring($im,3,36,50,"点击了解详情 >>",$yel);
    imagepng($im,$path); imagedestroy($im);
}

$avatarDir=$root.'/public/uploads/avatars'; $qrDir=$root.'/public/uploads/qr'; $adDir=$root.'/public/uploads/ads';
ensureDir($avatarDir); ensureDir($qrDir); ensureDir($adDir);

// 注意：此处【不】包含 admins 表。管理员账号由安装向导(install.php)按你设置的
// 账号密码创建，演示数据绝不能清空或覆盖管理员（曾经因此把用户自定义管理员
// 删掉、换回 admin/admin888，已修复）。
$tables=['users','tips','posts','post_categories','comments','likes','favorites','follows','notifications','ads','qr_audit_logs','tip_confirm_logs','post_audit_logs','comment_audit_logs','reports','sensitive_words','admin_logs','risk_remind_logs','invite_codes','invite_used_logs'];
foreach($tables as $t){ $pdo->exec("DELETE FROM `$t`"); $pdo->exec("ALTER TABLE `$t` AUTO_INCREMENT=1"); }
echo "=> cleared\n";

$cats=[['默认分类',1,1,1],['生活随笔',2,1,0],['技术分享',3,1,0],['赚钱思路',4,1,0],['摄影作品',5,1,0]];
$catIds=[];
foreach($cats as $c){ $pdo->prepare("INSERT INTO post_categories (name,sort,status,is_default,created_at,updated_at) VALUES (?,?,?,?,?,?)")->execute([$c[0],$c[1],$c[2],$c[3],date('Y-m-d H:i:s'),date('Y-m-d H:i:s')]); $catIds[]=$pdo->lastInsertId(); }

$users=[
    ['设计师阿杰','#2563eb','用设计连接世界，分享每日灵感。'],
    ['旅行者小林','#f97316','走过山河，记录路上的光。'],
    ['美食家薇薇','#16a34a','好好吃饭，好好生活。'],
    ['程序员老K','#7c3aed','代码改变世界，也改变钱包。'],
    ['摄影师安然','#0ea5e9','捕捉转瞬即逝的温柔。'],
    ['读书人墨白','#db2777','读书是随身携带的避难所。'],
    ['健身教练大鹏','#ea580c','自律给我自由。'],
    ['音乐人阿声','#0891b2','把情绪写成旋律。'],
    ['手作达人糖糖','#ca8a04','一针一线，皆是心意。'],
    ['理财师老王','#65a30d','让每一分钱都更有价值。'],
    ['写作者清欢','#4f46e5','写下平凡日子里的微光。'],
    ['创业者阿卓','#dc2626','在路上，永远年轻。'],
    ['园丁晓风','#059669','种花种草，也种希望。'],
    ['自由职业喵','#9333ea','远程工作，环游世界。'],
];
$uidMap=[]; $bgs=['#2563eb','#f97316','#16a34a','#7c3aed','#0ea5e9','#db2777','#ea580c','#0891b2','#ca8a04','#65a30d','#4f46e5','#dc2626','#059669','#9333ea'];
foreach($users as $i=>$u){
    $username=$u[0]; $avatar='avatars/'.md5($username).'.png'; makeAvatar($avatarDir.'/'.basename($avatar),$username,$bgs[$i]);
    $hasQr=$i<9;
    $wechat=$hasQr?('qr/'.md5($username.'wx').'.png'):null; $alipay=$hasQr?('qr/'.md5($username.'ali').'.png'):null;
    if($hasQr){ makeQr($qrDir.'/'.basename($wechat),'微信收款码','#07c160'); makeQr($qrDir.'/'.basename($alipay),'支付宝收款码','#1677ff'); }
    $follower=mt_rand(5,320); $following=mt_rand(3,120); $postCount=mt_rand(2,12);
    $tipTotal=$hasQr?round(mt_rand(20,400)+mt_rand(0,99)/100,2):0;
    $created=date('Y-m-d H:i:s',strtotime('-'.mt_rand(20,400).' days'));
    $pdo->prepare("INSERT INTO users (username,password,avatar,bio,wechat_qr,alipay_qr,wechat_qr_status,alipay_qr_status,qr_type,role,status,tip_total_amount,post_count,follower_count,following_count,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$username,password_hash('abc123456',PASSWORD_BCRYPT),$avatar,$u[2],$wechat,$alipay,$hasQr?2:0,$hasQr?2:0,1,'user',1,$tipTotal,$postCount,$follower,$following,$created,$created]);
    $uidMap[$username]=$pdo->lastInsertId();
}
echo "=> users done\n";

$titles=['普通人也能上手的副业思路分享','我的极简摄影装备清单','周末在家做一杯手冲咖啡','从零开始学理财的第一课','远程工作一年，我总结了这些经验','用手机也能拍出电影感短片','读书笔记：把时间当作朋友','健身小白的第一百天','手账这样写，生活更有秩序','城市漫步，发现身边的美','写作让我重新认识自己','创业踩过的那些坑','阳台种花的快乐你想象不到','自由职业者的时间管理术'];
$bodies=[
    '<h2>写在前面</h2><p>这是一篇示例博文，用于展示富文本渲染效果。平台鼓励大家分享真实、有价值的经验。</p><ul><li>保持真诚，不夸大收益</li><li>图文并茂更易读</li><li>欢迎在评论区交流</li></ul><blockquote>分享本身，就是一种收获。</blockquote>',
    '<h2>我的清单</h2><table><thead><tr><th>物品</th><th>用途</th></tr></thead><tbody><tr><td>微单相机</td><td>日常记录</td></tr><tr><td>便携三脚架</td><td>稳定拍摄</td></tr></tbody></table><pre><code>function hello(){ return "world"; }</code></pre>',
    '<h2>步骤</h2><ol><li>准备器具</li><li>研磨咖啡豆</li><li>控制水温 90℃</li><li>匀速注水</li></ol><p>慢慢来，享受过程。</p>',
];
$ids=array_values($uidMap); $postIds=[];
foreach($titles as $i=>$title){
    $uid=$ids[$i%count($ids)]; $cat=$catIds[1+($i%(count($catIds)-1))];
    $body=$bodies[$i%count($bodies)]; $summary=mb_substr(strip_tags($body),0,60);
    $view=mt_rand(50,2000); $like=mt_rand(3,200); $comment=mt_rand(0,30);
    $created=date('Y-m-d H:i:s',strtotime('-'.mt_rand(1,200).' days'));
    $pdo->prepare("INSERT INTO posts (user_id,title,summary,content,cover,category_id,tags,status,view_count,like_count,comment_count,created_at,updated_at) VALUES (?,?,?,?,?,?,?,2,?,?,?,?,?)")
        ->execute([$uid,$title,$summary,$body,null,$cat,'分享,经验',$view,$like,$comment,$created,$created]);
    $postIds[]=$pdo->lastInsertId();
}
foreach(array_slice($ids,0,2) as $k=>$uid){ $pdo->prepare("INSERT INTO posts (user_id,title,content,status,created_at,updated_at) VALUES (?,?,?,0,?,?)")->execute([$uid,'未完成的草稿'.($k+1),'<p>这是一篇草稿，仅自己可见。</p>',date('Y-m-d H:i:s'),date('Y-m-d H:i:s')]); }
echo "=> posts done\n";

$ctext=['说得太对了，受教了！','收藏起来慢慢看～','请问具体怎么操作呢？','已打赏支持，加油！','写得真好，期待下一篇。','这个思路很新颖。','路过支持一下。','博主用心了。'];
foreach(array_slice($postIds,0,10) as $pid){
    $topUid=$ids[mt_rand(0,count($ids)-1)];
    $pdo->prepare("INSERT INTO comments (target_type,target_id,user_id,parent_id,content,status,is_audit,created_at) VALUES (1,?,?,0,?,1,0,?)")->execute([$pid,$topUid,$ctext[mt_rand(0,7)],date('Y-m-d H:i:s',strtotime('-'.mt_rand(0,20).' days'))]);
    $topId=$pdo->lastInsertId();
    for($r=0;$r<mt_rand(0,3);$r++){ $ru=$ids[mt_rand(0,count($ids)-1)]; $pdo->prepare("INSERT INTO comments (target_type,target_id,user_id,parent_id,content,status,is_audit,created_at) VALUES (1,?,?,?,?,1,0,?)")->execute([$pid,$ru,$topId,$ctext[mt_rand(0,7)],date('Y-m-d H:i:s',strtotime('-'.mt_rand(0,10).' days'))]); }
}
echo "=> comments done\n";

$levels=[1,2,3]; $payTypes=[1,2]; $statuses=[0,0,1,1,1,2];
foreach($ids as $from){
    $toC=array_values(array_diff($ids,[$from]));
    for($t=0;$t<mt_rand(1,4);$t++){
        $to=$toC[mt_rand(0,count($toC)-1)]; $level=$levels[mt_rand(0,2)]; $amount=[1=>0.01,2=>0.10,3=>1.00][$level]; $pay=$payTypes[mt_rand(0,1)]; $status=$statuses[mt_rand(0,count($statuses)-1)];
        $created=date('Y-m-d H:i:s',strtotime('-'.mt_rand(0,30).' days')); $confirmAt=$status==1?$created:null; $reject=$status==2?'超时未收到，疑似未转账':null;
        $pdo->prepare("INSERT INTO tips (from_user_id,from_ip,to_user_id,amount,level,pay_type,remark,status,confirm_at,reject_reason,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute([$from,'127.0.0.1',$to,$amount,$level,$pay,'支持一下！',$status,$confirmAt,$reject,$created]);
        if($status==2) $pdo->prepare("INSERT INTO tip_confirm_logs (tip_id,action,operator_type,operator_id,remark,created_at) VALUES (?,?,?,?,?,?)")->execute([$pdo->lastInsertId(),'标记异常',1,$to,$reject,$created]);
    }
}
echo "=> tips done\n";

$rows=$pdo->query("SELECT to_user_id,status,COUNT(*) c,SUM(amount) a FROM tips GROUP BY to_user_id,status")->fetchAll(PDO::FETCH_ASSOC);
$stat=[]; foreach($rows as $r){ $uid=$r['to_user_id']; $stat[$uid]['total']=($stat[$uid]['total']??0)+(in_array($r['status'],[0,1])?$r['a']:0); if($r['status']==1)$stat[$uid]['confirmed']=($stat[$uid]['confirmed']??0)+$r['c']; if($r['status']==2)$stat[$uid]['rejected']=($stat[$uid]['rejected']??0)+$r['c']; }
foreach($stat as $uid=>$s) $pdo->prepare("UPDATE users SET tip_total_amount=?,tip_confirmed_count=?,tip_rejected_count=? WHERE id=?")->execute([round($s['total'],2),$s['confirmed']??0,$s['rejected']??0,$uid]);
$sent=$pdo->query("SELECT from_user_id,COUNT(*) c FROM tips GROUP BY from_user_id")->fetchAll(PDO::FETCH_ASSOC);
foreach($sent as $r) $pdo->prepare("UPDATE users SET sent_tip_count=? WHERE id=?")->execute([$r['c'],$r['from_user_id']]);
echo "=> stats rebuilt\n";

foreach($ids as $a){ $n=mt_rand(1,4); $others=array_values(array_diff($ids,[$a])); shuffle($others); for($k=0;$k<$n;$k++){ $b=$others[$k]; $pdo->prepare("INSERT INTO follows (user_id,follower_id,created_at) VALUES (?,?,?)")->execute([$b,$a,date('Y-m-d H:i:s',strtotime('-'.mt_rand(0,100).' days'))]); } }
echo "=> follows done\n";

$first=$ids[0]; $noti=[[7,'收到一笔打赏','设计师阿杰 向你打赏了 ¥1.00'],[6,'新增关注','旅行者小林 关注了你'],[1,'新的评论','美食家薇薇 评论了你的博文'],[10,'风控提醒','你的收款较为活跃，建议升级商户收款码'],[4,'点赞通知','程序员老K 赞了你的博文']];
foreach($noti as $n) $pdo->prepare("INSERT INTO notifications (user_id,type,title,content,related_id,is_read,created_at) VALUES (?,?,?,?,0,?,?)")->execute([$first,$n[0],$n[1],$n[2],mt_rand(0,1),date('Y-m-d H:i:s',strtotime('-'.mt_rand(0,5).' days'))]);
echo "=> notifications done\n";

$adSlots=['square_top'=>['新用户专享 · 内容创作激励计划','#2563eb'],'square_mid'=>['优质创作者招募中','#f97316'],'square_bottom'=>['社区公约：理性打赏，谨防诈骗','#16a34a'],'posts_top'=>['发现更多好内容','#7c3aed'],'post_side'=>['成为认证创作者','#0ea5e9'],'profile_side'=>['升级商户码 保障收款','#dc2626'],'user_side'=>['关注你喜欢的人','#ca8a04']];
foreach($adSlots as $slot=>$cfg){ $img='ads/'.md5($slot).'.png'; makeAd($adDir.'/'.basename($img),$cfg[0],$cfg[1]); $now=date('Y-m-d H:i:s'); $pdo->prepare("INSERT INTO ads (slot,title,image,url,start_at,end_at,sort,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,1,?,?)")->execute([$slot,$cfg[0],$img,'/help/merchant-qr',date('Y-m-d H:i:s',strtotime('-10 days')),date('Y-m-d H:i:s',strtotime('+30 days')),1,$now,$now]); }
echo "=> ads done\n";

foreach(['诈骗','赌博','代开发票'] as $w) $pdo->prepare("INSERT INTO sensitive_words (word,strategy,status,created_at) VALUES (?,2,1,?)")->execute([$w,date('Y-m-d H:i:s')]);
foreach(['WELCOME2026','INVITE888','VIP666'] as $c) $pdo->prepare("INSERT INTO invite_codes (code,batch,total_quota,used_count,status,created_at,updated_at) VALUES (?,?,5,0,1,?,?)")->execute([$c,'seed',date('Y-m-d H:i:s'),date('Y-m-d H:i:s')]);
echo "=> sensitive + invite done\n";
echo "\nDONE\n";
