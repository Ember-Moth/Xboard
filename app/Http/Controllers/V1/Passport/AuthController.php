<?php

namespace App\Http\Controllers\V1\Passport;

use App\Helpers\ResponseEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Services\Auth\LoginService;
use App\Services\Auth\MailLinkService;
use App\Services\Auth\RegisterService;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;

class AuthController extends Controller
{
    protected MailLinkService $mailLinkService;
    protected RegisterService $registerService;
    protected LoginService $loginService;

    public function __construct(
        MailLinkService $mailLinkService,
        RegisterService $registerService,
        LoginService $loginService
    ) {
        $this->mailLinkService = $mailLinkService;
        $this->registerService = $registerService;
        $this->loginService = $loginService;
    }

    /**
     * 通过邮件链接登录
     */
    public function loginWithMailLink(Request $request)
    {
        $params = $request->validate([
            'email' => 'required|email:strict',
            'redirect' => 'nullable'
        ]);

        [$success, $result] = $this->mailLinkService->handleMailLink(
            $params['email'],
            $request->input('redirect')
        );

        if (!$success) {
            return $this->fail($result);
        }

        return $this->success($result);
    }

    /**
     * 用户注册
     */
    public function register(AuthRegister $request)
    {
        [$success, $result] = $this->registerService->register($request);

        if (!$success) {
            return $this->fail($result);
        }

        $authService = new AuthService($result);
        return $this->success($authService->generateAuthData());
    }

    /**
     * 用户登录
     */
    public function login(AuthLogin $request)
    {
        $email = $request->input('email');
        $password = $request->input('password');

        [$success, $result] = $this->loginService->login($email, $password);

        if (!$success) {
            return $this->fail($result);
        }

        $authService = new AuthService($result);
        return $this->success($authService->generateAuthData());
    }

    /**
     * 通过token登录
     */
    public function token2Login(Request $request)
    {
        if ($token = $request->input('token')) {
            $redirect = '/#/login?verify=' . $token . '&redirect=' . ($request->input('redirect', 'dashboard'));

            return redirect()->to(
                admin_setting('app_url')
                    ? admin_setting('app_url') . $redirect
                    : url($redirect)
            );
        }

        if ($verify = $request->input('verify')) {
            $userId = $this->mailLinkService->handleTokenLogin($verify);

            if (!$userId) {
                return response()->json([
                    'message' => __('Token error')
                ], 400);
            }

            $user = \App\Models\User::find($userId);

            if (!$user) {
                return response()->json([
                    'message' => __('User not found')
                ], 400);
            }

            $authService = new AuthService($user);

            return response()->json([
                'data' => $authService->generateAuthData()
            ]);
        }

        return response()->json([
            'message' => __('Invalid request')
        ], 400);
    }

    /**
     * 获取快速登录URL
     */
    public function getQuickLoginUrl(Request $request)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');

        if (!$authorization) {
            return response()->json([
                'message' => ResponseEnum::CLIENT_HTTP_UNAUTHORIZED
            ], 401);
        }

        $user = AuthService::findUserByBearerToken($authorization);

        if (!$user) {
            return response()->json([
                'message' => ResponseEnum::CLIENT_HTTP_UNAUTHORIZED_EXPIRED
            ], 401);
        }

        $url = $this->mailLinkService->getQuickLoginUrl($user, $request->input('redirect'));
        return $this->success($url);
    }

    /**
     * 忘记密码处理
     */
    public function forget(AuthForget $request)
    {
        [$success, $result] = $this->loginService->resetPassword(
            $request->input('email'),
            $request->input('email_code'),
            $request->input('password')
        );

        if (!$success) {
            return $this->fail($result);
        }

        return $this->success(true);
    }

    /**
     * 重定向到 Google 登录页面
     */
    public function redirectToGoogle()
    {
        if (!(int)admin_setting('google_login_enable', 0)) {
            return response()->json([
                'message' => __('Google login is disabled')
            ], 403);
        }

        return Socialite::driver('google')->redirect();
    }

    /**
     * 处理 Google 登录回调
     */
    public function handleGoogleCallback()
    {
        if (!(int)admin_setting('google_login_enable', 0)) {
            return response()->json([
                'message' => __('Google login is disabled')
            ], 403);
        }

        try {
            $googleUser = Socialite::driver('google')->user();
            $user = User::where('google_id', $googleUser->id)->first();

            if (!$user) {
                $user = User::where('email', $googleUser->email)->first();

                if ($user) {
                    $user->google_id = $googleUser->id;
                    $user->save();
                } else {
                    $user = new User();
                    $user->email = $googleUser->email;
                    $user->google_id = $googleUser->id;
                    $user->uuid = \App\Utils\Helper::guid(true);
                    $user->token = \App\Utils\Helper::guid();
                    $user->remind_expire = admin_setting('default_remind_expire', 1);
                    $user->remind_traffic = admin_setting('default_remind_traffic', 1);
                    $this->registerService->handleTryOut($user);
                    $user->save();
                }
            }

            if ($user->banned) {
                return response()->json([
                    'message' => __('Your account has been suspended')
                ], 400);
            }

            $user->last_login_at = time();
            $user->save();

            $authService = new AuthService($user);
            return response()->json([
                'data' => $authService->generateAuthData()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => __('Google login failed: :error', ['error' => $e->getMessage()])
            ], 400);
        }
    }
}
