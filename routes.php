<?php
//
// Copyright 2023 Chatura Dilan Perera. All rights reserved.
// Use of this source code is governed by license that can be
//  found in the LICENSE file.
// Website: https://www.dilan.me
//

use Illuminate\Http\Request;
use RainLab\User\Models\User;
use Dilexus\Octobase\Models\Settings;
use Dilexus\Octobase\Classes\Api\Lib\Utils;
use Kreait\Firebase\Exception\AppCheck\FailedToVerifyAppCheckToken;

Route::prefix('octobase')->group(function () {

    Route::post('login', function (Request $request) {

        if (Settings::get('login_disabled')) {
            return response()->json(['message' => 'User login is disabled'], 403);
        }

        if (Settings::get('enable_firebase_appcheck_on_manual_login')) {
            $firebase_credentials = Settings::get('firebase_credentials');
            if (!empty($firebase_credentials)) {
                $factory = (new \Kreait\Firebase\Factory)->withServiceAccount($firebase_credentials);
            } else {
                $factory = (new \Kreait\Firebase\Factory)->withServiceAccount('config/firebase_credentials.json');
            }
            try {
                $appcheckToken = $request->header('X-Firebase-AppCheck');
                if ($appcheckToken === null) {
                    return response()->json(['message' => "Invalid Appcheck Token"], 400);
                }
                $appCheck = $factory->createAppCheck();
                $appCheck->verifyToken($request->header('X-Firebase-AppCheck'));
            } catch (FailedToVerifyAppCheckToken $e) {
                return response()->json(['message' => $e->getMessage()], 400);
            }

        }

        try {
            if (Auth::check()) {
                Auth::logout();
            }
            Auth::attempt([
                'email' => $request->input('email'),
                'password' => $request->input('password'),
            ], true);

            $user = Auth::user();

            if (Settings::get('one_session_per_user')) {
                if (Auth::check()) {
                    Auth::logout();
                }
            }

            if (!$user) {
                return response()->json(['message' => 'No user exists for authentication purposes'], 401);
            }

            return response()->json([
                'id' => $user['id'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'email' => $user['email'],
                'username' => $user['username'],
                'groups' => $user['groups']->lists('code'),
                'token' => Crypt::encryptString($user->getRememberToken()),
            ]);

        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    });

    Route::post('logout', function (Request $request) {
        try {
            $authroization = $request->header('Authorization');
            try {
                $token = Crypt::decryptString(str_replace('Bearer ', '', $authroization));
            } catch (\Exception $e) {
                return response()->json(['message' => 'Invalid Token'], 401);
            }
            $user = User::where('remember_token', $token)->first();
            if (!$user) {
                return response()->json(['message' => 'Unauthroized acceess, Expired Token'], 401);
            }
            Auth::login($user, true);
            Auth::logout();
            return response()->json(['success' => 'Signout Success']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    });

    Route::post('check', function (Request $request) {
        try {
            $authroization = $request->header('Authorization');
            try {
                $token = Crypt::decryptString(str_replace('Bearer ', '', $authroization));
            } catch (\Exception $e) {
                return response()->json(['message' => 'Invalid Token'], 401);
            }
            $user = User::where('remember_token', $token)->first();
            if (!$user) {
                return response()->json(['message' => 'Token Expired'], 404);
            }
            return response()->json(['success' => 'Token Exists'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    });

    Route::post('register', function (Request $request) {

        try {
            $registration_disabled = Settings::get('registration_disabled');
            $require_activation = Settings::get('require_activation');
            if ($registration_disabled) {
                return response()->json(['message' => 'User registration is disabled'], 403);
            }

            if (Settings::get('enable_firebase_appcheck_on_manual_login')) {
                $firebase_credentials = Settings::get('firebase_credentials');
                if (!empty($firebase_credentials)) {
                    $factory = (new \Kreait\Firebase\Factory)->withServiceAccount($firebase_credentials);
                } else {
                    $factory = (new \Kreait\Firebase\Factory)->withServiceAccount('config/firebase_credentials.json');
                }
                try {
                    $appcheckToken = $request->header('X-Firebase-AppCheck');
                    if ($appcheckToken === null) {
                        return response()->json(['message' => "Invalid Appcheck Token"], 400);
                    }
                    $appCheck = $factory->createAppCheck();
                    $appCheck->verifyToken($request->header('X-Firebase-AppCheck'));
                } catch (FailedToVerifyAppCheckToken $e) {
                    return response()->json(['message' => $e->getMessage()], 400);
                }

            }

            $payload = [
                'first_name' => $request->input('first_name'),
                'last_name' => $request->input('last_name'),
                'email' => $request->input('email'),
                'username' => $request->input('username'),
                'password' => $request->input('password'),
                'password_confirmation' => $request->input('password_confirmation'),
            ];
            $authUser = Auth::register($payload, $require_activation);
            Auth::setUser($authUser);
            Auth::login($authUser, true);
            $authUser->groups()->attach(2);
            $avatar = $authUser['avatar'];
            if ($avatar) {
                $avatar = ['path' => $avatar['path'], 'extenstion' => $avatar['extension']];
            }
            return response()->json([
                'id' => $authUser['id'],
                'first_name' => $authUser['first_name'],
                'last_name' => $authUser['last_name'],
                'email' => $authUser['email'],
                'username' => $authUser['username'],
                'is_activated' => $authUser['is_activated'],
                'groups' => $authUser['groups']->lists('code'),
                'avatar' => $avatar,
                'is_new' => true,
                'token' => Crypt::encryptString($authUser->getRememberToken())], 201
            );

        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    });

    Route::get('user', function (Request $request) {
        try {
            $authroization = $request->header('Authorization');
            try {
                $token = Crypt::decryptString(str_replace('Bearer ', '', $authroization));
            } catch (\Exception $e) {
                return response()->json(['message' => 'Invalid Token'], 401);
            }
            $user = User::where('remember_token', $token)->first();
            if (!$user) {
                return response()->json(['message' => 'Unauthroized acceess, Token Expired'], 401);
            }

            $authUser = Auth::findUserById($user->id);

            if ($authUser) {
                $avatar = $authUser['avatar'];
                if ($avatar) {
                    $avatar = ['path' => $avatar['path'], 'extenstion' => $avatar['extension']];
                }
                return response()->json([
                    'id' => $authUser['id'],
                    'first_name' => $authUser['first_name'],
                    'last_name' => $authUser['last_name'],
                    'email' => $authUser['email'],
                    'username' => $authUser['username'],
                    'is_activated' => $authUser['is_activated'],
                    'groups' => $authUser['groups']->lists('code'),
                    'avatar' => $avatar,
                    'token' => str_replace('Bearer ', '', $authroization)]
                );

            } else {
                return response()->json(['message' => 'User not Found for the given token'], 404);
            }
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    });

    Route::post('refresh', function (Request $request) {
        try {

            if (Settings::get('enable_firebase_appcheck_on_manual_login')) {
                $firebase_credentials = Settings::get('firebase_credentials');
                if (!empty($firebase_credentials)) {
                    $factory = (new \Kreait\Firebase\Factory)->withServiceAccount($firebase_credentials);
                } else {
                    $factory = (new \Kreait\Firebase\Factory)->withServiceAccount('config/firebase_credentials.json');
                }
                try {
                    $appcheckToken = $request->header('X-Firebase-AppCheck');
                    if ($appcheckToken === null) {
                        return response()->json(['message' => "Invalid Appcheck Token"], 400);
                    }
                    $appCheck = $factory->createAppCheck();
                    $appCheck->verifyToken($request->header('X-Firebase-AppCheck'));
                } catch (FailedToVerifyAppCheckToken $e) {
                    return response()->json(['message' => $e->getMessage()], 400);
                }

            }

            $authroization = $request->header('Authorization');
            try {
                $token = Crypt::decryptString(str_replace('Bearer ', '', $authroization));
            } catch (\Exception $e) {
                return response()->json(['message' => 'Invalid Token'], 401);
            }
            $user = User::where('remember_token', $token)->first();
            if (!$user) {
                return response()->json(['message' => 'Unauthroized acceess, Token Expired'], 401);
            }
            Auth::login($user, true);
            if (Auth::check()) {
                Auth::logout();
            }
            Auth::login($user, true);

            if ($user) {
                $avatar = $user['avatar'];
                if ($avatar) {
                    $avatar = ['path' => $avatar['path'], 'extenstion' => $avatar['extension']];
                }
                return response()->json([
                    'id' => $user['id'],
                    'first_name' => $user['name'],
                    'last_name' => $user['surname'],
                    'email' => $user['email'],
                    'username' => $user['username'],
                    'is_activated' => $user['is_activated'],
                    'groups' => $user['groups']->lists('code'),
                    'avatar' => $avatar,
                    'token' => Crypt::encryptString($user->getRememberToken())]
                );

            } else {
                return response()->json(['message' => 'User Not Found for the given token'], 400);
            }
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    });

    Route::post('login/firebase', function (Request $request) {
        try {

            $idTokenString = $request->input('token');
            $firebase_credentials = Settings::get('firebase_credentials');
            if (!empty($firebase_credentials)) {
                $factory = (new \Kreait\Firebase\Factory)->withServiceAccount($firebase_credentials);
            } else {
                $factory = (new \Kreait\Firebase\Factory)->withServiceAccount('config/firebase_credentials.json');
            }

            if (Settings::get('enable_firebase_appcheck_on_firebase_login')) {
                $appcheckToken = $request->header('X-Firebase-AppCheck');
                if ($appcheckToken === null) {
                    return response()->json(['message' => "Invalid Appcheck Token"], 400);
                }
                $appCheck = $factory->createAppCheck();
                $appCheck->verifyToken($request->header('X-Firebase-AppCheck'));
            }

            $auth = $factory->createAuth();
            $verifiedIdToken = $auth->verifyIdToken($idTokenString);
            $uid = $verifiedIdToken->claims()->get('sub');
            $user = $auth->getUser($uid);

            $authUser = User::where('email', $user->email)->first();
            if (!$authUser) {
                $require_activation = Settings::get('require_activation');
                $randomPass = Utils::randomPassword();

                $payload = [
                    'first_name' => $user->displayName,
                    'last_name' => $user->uid,
                    'email' => $user->email,
                    'username' => $user->uid,
                    'password' => $randomPass,
                    'password_confirmation' => $randomPass,
                ];
                $authUser = Auth::register($payload, $require_activation);
                Auth::setUser($authUser);
                Auth::login($authUser, true);
                if (Settings::get('one_session_per_user')) {
                    if (Auth::check()) {
                        Auth::logout();
                    }
                }
                $groups = Settings::get('default_groups');
                if ($groups) {
                    $groups = array_map('intval', explode(',', $groups));
                } else {
                    $groups = [2];
                }
                $authUser->groups()->attach($groups);
                $avatar = $authUser['avatar'];
                if ($avatar) {
                    $avatar = ['path' => $avatar['path'], 'extenstion' => $avatar['extension']];
                }
                return response()->json([
                    'id' => $authUser['id'],
                    'first_name' => $authUser['first_name'],
                    'last_name' => $authUser['last_name'],
                    'email' => $authUser['email'],
                    'username' => $authUser['username'],
                    'is_activated' => $authUser['is_activated'],
                    'groups' => $authUser['groups']->lists('code'),
                    'avatar' => $avatar,
                    'is_new' => true,
                    'token' => Crypt::encryptString($authUser->getRememberToken())], 201
                );
            } else {
                Auth::setUser($authUser);
                Auth::login($authUser, true);
                if (Settings::get('one_session_per_user')) {
                    if (Auth::check()) {
                        Auth::logout();
                    }
                }
                if ($authUser) {
                    $avatar = $authUser['avatar'];
                    if ($avatar) {
                        $avatar = ['path' => $avatar['path'], 'extenstion' => $avatar['extension']];
                    }
                    return response()->json([
                        'id' => $authUser['id'],
                        'first_name' => $authUser['first_name'],
                        'last_name' => $authUser['last_name'],
                        'email' => $authUser['email'],
                        'username' => $authUser['username'],
                        'is_activated' => $authUser['is_activated'],
                        'groups' => $authUser['groups']->lists('code'),
                        'avatar' => $avatar,
                        'token' => Crypt::encryptString($authUser->getRememberToken())]
                    );

                } else {
                    Auth::logout();
                    return response()->json(['message' => 'User Not Found for the given token'], 400);
                }
            }

        } catch (\Exception $e) {
            Auth::logout();
            return response()->json(['message' => $e->getMessage()], 400);
        }
    });

});
