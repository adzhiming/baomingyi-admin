<?php

namespace App\Http\Controllers\Api;

use App\Events\UserLoginLogEvent;
use App\Logic\UsersLogic;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\JwtAuthService;
use App\Services\SmsCodeService;

class IndexController extends BaseController
{
    /**
     * 注册接口
     *
     * @param Request $request
     * @param UsersLogic $usersLogic
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request, UsersLogic $usersLogic)
    {
        $fields = ['nickname', 'mobile', 'password', 'sms_code'];
        if (!$request->filled($fields)) {
            return $this->ajaxParamError();
        }

        $params = $request->only($fields);
        if (!check_mobile($params['mobile'])) {
            return $this->ajaxParamError('手机号格式不正确...');
        }

        $sms = new SmsCodeService();
        if (!$sms->check('user_register', $params['mobile'], $params['sms_code'])) {
            //return $this->ajaxParamError('验证码填写错误...');
        }

        $isTrue = $usersLogic->register([
            'mobile' => $params['mobile'],
            'password' => $params['password'],
            'nickname' => strip_tags($params['nickname']),
        ]);

        if ($isTrue) {
            $sms->delCode('user_register', $params['mobile']);
        }

        return $isTrue ? $this->ajaxSuccess('账号注册成功...') : $this->ajaxError('账号注册失败,手机号已被其他(她)人使用...');
    }

    /**
     * 登录接口
     *
     * @param Request $request
     * @param UsersLogic $usersLogic
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request, UsersLogic $usersLogic)
    {
        if (!$request->filled(['mobile', 'password'])) {
            return $this->ajaxParamError();
        }

        $data = $request->only(['mobile', 'password']);
        $user = User::where('mobile', $data['mobile'])->first();
        if (!$user) {
            return $this->ajaxReturn(302, '登录账号不存在...');
        }

        if (!$usersLogic->checkPassword($data['password'], $user->password)) {
            return $this->ajaxReturn(305, '登录密码错误...');
        }

        $jwt = new JwtAuthService();
        $jwtConfig = config('config.jwt');
        $jwtObject = $jwt->jwtObject();
        $jwtObject->setAlg($jwtConfig['algo']); // 加密方式
        $jwtObject->setAud('user'); // 用户
        $jwtObject->setExp(time() + $jwtConfig['ttl']); //  jwt的过期时间，这个过期时间必须要大于签发时间
        $jwtObject->setIat(time()); // 发布时间
        $jwtObject->setIss('lumen-im'); // 发行人
        $jwtObject->setJti(md5(time() . mt_rand(10000, 99999) . uniqid())); // jwt id 用于标识该jwt
        $jwtObject->setNbf(time()); // 定义在什么时间之前，该jwt都是不可用的.
        $jwtObject->setSub('Authorized login'); // 主题
        $jwtObject->setData([
            'uid' => $user->id
        ]);

        if (!$token = $jwtObject->token()) {
            return $this->ajaxReturn(305, '获取登录状态失败');
        }

        // 记录登录日志
        event(new UserLoginLogEvent($user->id, $request->getClientIp()));

        return $this->ajaxReturn(200, '授权登录成功', [
            // 授权信息
            'authorize' => [
                'access_token' => $token,
                'expires_in' => $jwtObject->getExp() - time(),
            ],

            // 用户信息
            'userInfo' => [
                'uid' => $user->id,
                'nickname' => $user->nickname,
                'avatar' => $user->avatar,
                'motto' => $user->motto,
                'gender' => $user->gender,
            ]
        ]);
    }

    /**
     * 退出登录
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function logout()
    {
        $token = parse_token();

        app('jwt.auth')->joinBlackList($token, app('jwt.auth')->decode($token)->getExp() - time());

        return $this->ajaxReturn(200, '退出成功...', []);
    }

    /**
     * 发送验证码
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendVerifyCode(Request $request)
    {
        $mobile = $request->post('mobile', '');
        $type = $request->post('type', '');
        if (!$request->filled(['mobile', 'type'])) {
            return $this->ajaxParamError();
        }

        if (!in_array($type, app('sms.code')->getTags())) {
            return $this->ajaxParamError('验证码发送失败...');
        }

        if (!check_mobile($mobile)) {
            return $this->ajaxParamError('手机号格式错误...');
        }

        if ($type == 'forget_password') {
            if (!User::where('mobile', $mobile)->value('id')) {
                return $this->ajaxParamError('手机号未被注册使用...');
            }
        } else if ($type == 'change_mobile' || $type == 'user_register') {
            if (User::where('mobile', $mobile)->value('id')) {
                return $this->ajaxParamError('手机号已被他(她)人注册...');
            }
        }

        $data = ['is_debug' => true];
        [$isTrue, $result] = app('sms.code')->send($type, $mobile);
        if ($isTrue) {
            $data['sms_code'] = $result['data']['code'];
        } else {
            // ... 处理发送失败逻辑，当前默认发送成功
        }

        return $this->ajaxSuccess('发送成功', $data);
    }

    /**
     * 重置用户密码
     *
     * @param Request $request
     * @param UsersLogic $usersLogic
     * @return \Illuminate\Http\JsonResponse
     */
    public function forgetPassword(Request $request, UsersLogic $usersLogic)
    {
        $mobile = $request->post('mobile', '');
        $code = $request->post('sms_code', '');
        $password = $request->post('password', '');

        if (!check_mobile($mobile) || empty($code) || empty($password)) {
            return $this->ajaxParamError();
        }

        if (!check_password($password)) {
            //return $this->ajaxParamError('密码格式不正确...');
        }
        $sms = new SmsCodeService();
        if (!$sms->check('forget_password', $mobile, $code)) {
           // return $this->ajaxParamError('验证码填写错误...');
        }

        $isTrue = $usersLogic->resetPassword($mobile, $password);
        if ($isTrue) {
            $sms->delCode('forget_password', $mobile);
        }

        return $isTrue ? $this->ajaxSuccess('重置密码成功...') : $this->ajaxError('重置密码失败...');
    }

    //
    public function gettoken(){
        $jwt = new JwtAuthService();
        $jwtConfig = config('config.jwt');
        $jwtObject = $jwt->jwtObject();
        $jwtObject->setAlg($jwtConfig['algo']); // 加密方式
        $jwtObject->setAud('user'); // 用户
        $jwtObject->setExp(time() + $jwtConfig['ttl']); //  jwt的过期时间，这个过期时间必须要大于签发时间
        $jwtObject->setIat(time()); // 发布时间
        $jwtObject->setIss('lumen-im'); // 发行人
        $jwtObject->setJti(md5(time() . mt_rand(10000, 99999) . uniqid())); // jwt id 用于标识该jwt
        $jwtObject->setNbf(time()); // 定义在什么时间之前，该jwt都是不可用的.
        $jwtObject->setSub('Authorized login'); // 主题
        $jwtObject->setData([
            'uid' => 1
        ]);

        if (!$token = $jwtObject->token()) {
            return $this->ajaxReturn(305, '获取登录状态失败');
        }

        // 记录登录日志
        event(new UserLoginLogEvent(1, '123'));

        return $this->ajaxReturn(200, '授权登录成功', [
            // 授权信息
            'authorize' => [
                'access_token' => $token,
                'expires_in' => $jwtObject->getExp() - time(),
            ],

            // 用户信息
            'userInfo' => [
                'uid' => 1,
                'nickname' => 2,
                'avatar' => 3,
                'motto' => 4,
                'gender' => 5,
            ]
        ]);
    }
}
