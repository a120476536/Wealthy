<?php
namespace app\controller;

use support\Request;
use support\View;

class HelpController
{
    public function merchantQr(Request $request)
    {
        return view('help/merchant_qr', []);
    }

    public function agreement(Request $request)
    {
        return view('help/agreement', []);
    }
}
