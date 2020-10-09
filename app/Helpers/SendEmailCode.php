<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;

class SendEmailCode
{
    const FORGET_PASSWORD = 'forget_password';
    const CHANGE_MOBILE = 'change_mobile';
    const CHANGE_REGISTER = 'user_register';
    const CHANGE_EMAIL = 'change_email';

    private function getKey(string $type, string $mobile)
    {
        return "email_code:{$type}:{$mobile}";
    }

    /**
     * 检测验证码是否正确
     *
     * @param string $type 发送类型
     * @param string $email 手机号
     * @param string $code 验证码
     * @return bool
     */
    public function check(string $type, string $email, string $code)
    {
        $sms_code = Redis::get($this->getKey($type, $email));
        if (!$sms_code) {
            return false;
        }

        return $sms_code == $code;
    }

    /**
     * 发送邮件验证码
     *
     * @param string $type 类型
     * @param string $title 邮件标题
     * @param string $email 邮箱地址
     * @return boolean
     */
    public function send(string $type,string $title, string $email)
    {
        $key = $this->getKey($type, $email);
        if (!$sms_code = $this->getCode($key)) {
            $sms_code = random(6, 'number');
        }

        $this->setCode($key, $sms_code);
        try{
            Mail::send('emails.verify-code', ['service_name' => $title, 'sms_code' => $sms_code, 'domain' => config('config.domain.web_url')], function ($message) use ($email, $title) {
                $message->to($email)->subject("{$title}(验证码)");
            });
        }catch (\Exception $e){
            return false;
        }

        return true;
    }

    /**
     * 获取缓存的验证码
     *
     * @param string $key
     * @return mixed
     */
    public function getCode(string $key)
    {
        return Redis::get($key);
    }

    /**
     * 设置验证码缓存
     *
     * @param string $key 缓存key
     * @param string $sms_code 验证码
     * @param float|int $exp 过期时间
     * @return mixed
     */
    public function setCode(string $key, string $sms_code, $exp = 60 * 15)
    {
        return Redis::setex($key, $exp, $sms_code);
    }

    /**
     * 删除验证码缓存
     *
     * @param string $type 类型
     * @param string $email 邮箱地址
     * @return mixed
     */
    public function delCode(string $type, string $email)
    {
        return Redis::del($this->getKey($type, $email));
    }
}
