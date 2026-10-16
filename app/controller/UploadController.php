<?php
namespace app\controller;

use support\Request;
use support\Response;

class UploadController
{
    // 图片上传（富文本/二维码）——需登录；服务端压缩后保存并返回可访问 URL
    //
    // 压缩策略（安全优先）：
    //  - 入口先校验类型 / 体积上限，挡住明显异常与 DoS。
    //  - PNG（多为收款二维码）保持无损，确保扫码可用；不强行转 JPEG。
    //  - JPG / WebP（多为文章配图）重编码为 JPEG，宽高上限 1600px，
    //    质量从 85 逐级下调，尽量压到 100KB 以内。
    //  - 若 GD 不可用或读图失败，回退为直接保存原文件，保证上传不中断。
    public function image(Request $request): Response
    {
        $uid = $request->session()->get('user_id');
        if (!$uid) {
            return new Response(401, [], '请先登录');
        }
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return new Response(400, [], '上传失败');
        }
        $ext = strtolower($file->getUploadExtension() ?: $file->getClientOriginalExtension());
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return new Response(400, [], '仅支持 JPG/PNG/WEBP');
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return new Response(400, [], '图片不能超过 5MB');
        }

        $dir = base_path() . '/public/uploads/qr';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $name = md5(uniqid($uid, true));

        // 尝试用 GD 处理（压缩/缩放）；失败（null 或 false）则保存原文件
        $src = $this->gdLoad($ext, $file->getPathname());
        if (!$src) {
            $name .= '.' . $ext;
            $file->move($dir . '/' . $name);
            return new Response(200, [], '/uploads/qr/' . $name);
        }

        // 等比缩放到最大边 1600px
        $maxDim = 1600;
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w > $maxDim || $h > $maxDim) {
            $ratio = min($maxDim / $w, $maxDim / $h);
            $nw = max(1, (int)round($w * $ratio));
            $nh = max(1, (int)round($h * $ratio));
            $dst = imagecreatetruecolor($nw, $nh);
            // 保留 PNG 透明通道
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $trans = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, $trans);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($src);
            $src = $dst;
        }

        // PNG：无损保存（二维码安全）
        if ($ext === 'png') {
            $name .= '.png';
            $ok = @imagepng($src, $dir . '/' . $name, 9);
        } else {
            // JPG / WEBP：压成 JPEG，质量下调直到 <=100KB 或触底
            $name .= '.jpg';
            $quality = 85;
            $dest = $dir . '/' . $name;
            do {
                $ok = @imagejpeg($src, $dest, $quality);
                $quality -= 10;
            } while ($ok && file_exists($dest) && filesize($dest) > 100 * 1024 && $quality >= 45);
        }
        imagedestroy($src);

        if (empty($ok) || !file_exists($dir . '/' . $name)) {
            return new Response(500, [], '图片处理失败');
        }
        return new Response(200, [], '/uploads/qr/' . $name);
    }

    /**
     * 用 GD 读取图片，失败返回 null
     */
    private function gdLoad(string $ext, string $path)
    {
        if (!extension_loaded('gd')) {
            return null;
        }
        try {
            switch ($ext) {
                case 'jpg':
                case 'jpeg':
                    return @imagecreatefromjpeg($path);
                case 'png':
                    return @imagecreatefrompng($path);
                case 'webp':
                    return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null;
                default:
                    return null;
            }
        } catch (\Throwable $e) {
            return null;
        }
    }
}
