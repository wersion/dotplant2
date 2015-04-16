<?php

namespace app\modules\user\controllers;

use app;
use app\modules\user\actions\AuthAction;
use app\modules\user\models\LoginForm;
use app\modules\user\models\PasswordResetRequestForm;
use app\modules\user\models\RegistrationForm;
use app\modules\user\models\ResetPasswordForm;
use app\modules\user\models\User;
use app\modules\user\models\UserService;
use app\seo\behaviors\MetaBehavior;
use Yii;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\base\InvalidParamException;
use yii\filters\AccessControl;
use yii\helpers\ArrayHelper;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use app\components\AuthClientHelper;

/**
 * Base DotPlant2 controller for handling user's login/signup/logout and etc. functions
 * @package app\controllers
 */
class UserController extends Controller
{
    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'seo' => [
                'class' => MetaBehavior::className(),
                'index' => $this->defaultAction,
            ],
            'access' => [
                'class' => AccessControl::className(),
                'only' => ['logout', 'signup', 'profile'],
                'rules' => [
                    [
                        'actions' => ['signup'],
                        'allow' => true,
                        'roles' => ['?'],
                    ],
                    [
                        'actions' => ['logout', 'profile'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function actions()
    {
        return [
            'auth' => [
                'class' => 'app\modules\user\actions\AuthAction',
                'successCallback' => [$this, 'successCallback'],
            ],
        ];
    }

    /**
     * Action for loggin in users
     * @param null $returnUrl
     * @return string|Response
     */
    public function actionLogin($returnUrl = null)
    {
        if (\Yii::$app->user->isGuest === false) {
            $this->goHome();
        }

        $model = new LoginForm();
        if ($model->load(Yii::$app->request->post()) && $model->login()) {
            return $this->goBack($returnUrl);
        } else {
            return $this->render(
                'login',
                [
                    'model' => $model,
                ]
            );
        }
    }

    /**
     * Action for user's logout
     * @return Response
     */
    public function actionLogout()
    {
        Yii::$app->user->logout();
        return $this->goHome();
    }

    /**
     * Action for standard registration handling
     * @return string|Response
     * @throws ErrorException
     */
    public function actionSignup()
    {
        $model = new RegistrationForm();
        if ($model->load(Yii::$app->request->post())) {
            $user = $model->signup();

            if ($user !== null) {
                if (Yii::$app->getUser()->login($user)) {
                    return $this->goHome();
                }
            } else {
                // there were errors
            }

        }

        return $this->render('signup', [
            'model' => $model,
        ]);
    }

    /**
     * Action for retrieving needed information from user that wasn't got from social network
     * @return string|Response
     */
    public function actionCompleteRegistration()
    {
        /** @var \app\modules\user\models\User $model */
        $model = Yii::$app->user->identity;

        if (intval($model->username_is_temporary) === 1) {
            // reset username
            $model->username = '';
        }
        $model->setScenario('completeRegistration');
        $model->load(Yii::$app->request->post());

        if (Yii::$app->request->isPost && $model->validate()) {

            $model->username_is_temporary = 0;
            $model->save();

            $auth_action = new AuthAction('post-registration', $this);
            return $auth_action->redirect('/');
        } else {
            $this->layout = $this->module->postRegistrationLayout;
            return $this->render('post-registration', [
                'model' => $model,
            ]);
        }
    }

    /**
     * Success callback for social networks authentication
     * @param $client
     * @throws ErrorException
     * @throws \yii\base\ExitException
     */
    public function successCallback($client)
    {
        $model = AuthClientHelper::findUserByService($client);
        if (is_object($model) === false) {
            // user not found
            $model = new User(['scenario' => 'registerService']);
            $security = Yii::$app->security;

            $client = AuthClientHelper::retrieveAdditionalData($client);

            $attributes = AuthClientHelper::mapUserAttributesWithService($client);

            $model->setAttributes($attributes['user']);
            $model->status = User::STATUS_ACTIVE;

            if (empty($model->username) === true) {
                // if we doesn't have username - generate unique random temporary username
                // it will be needed for saving purposes
                $model->username = $security->generateRandomString(18);
                $model->username_is_temporary = 1;
            }

            $model->setPassword($security->generateRandomString(16));

            $model->generateAuthKey();


            if ($model->save() === false) {

                if (isset($model->errors['username'])) {
                    // regenerate username
                    $model->username = $security->generateRandomString(18);
                    $model->username_is_temporary = 1;
                    $model->save();
                }

                if (isset($model->errors['email'])) {
                    // empty email
                    $model->email = null;
                    $model->save();
                }
                if (count($model->errors) > 0) {
                    throw new ErrorException("Temporary error signing up user");
                }
            }


            $service = new UserService();
            $service->service_type = $client->className();
            $service->service_id = $attributes['service']['service_id'];
            $service->user_id = $model->id;
            $service->save();

        }

        Yii::$app->user->login($model, 86400);

        if ($model->username_is_temporary == 1 || empty($model->email)) {
            // show post-registration form
            $this->layout = $this->module->postRegistrationLayout;
            $model->setScenario('completeRegistration');

            echo $this->render('post-registration', [
                'model' => $model,
            ]);
            Yii::$app->end();
            return;
        }
    }

    /**
     * Action for requesting password rest
     * @return string|Response
     */
    public function actionRequestPasswordReset()
    {
        $model = new PasswordResetRequestForm();
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            if ($model->sendEmail()) {
                Yii::$app->getSession()->setFlash(
                    'success',
                    Yii::t('app', 'Check your email for further instructions.')
                );

                return $this->goHome();
            } else {
                Yii::$app->getSession()->setFlash(
                    'error',
                    Yii::t('app', 'Sorry, we are unable to reset password for email provided.')
                );
            }
        }

        return $this->render('requestPasswordResetToken', [
            'model' => $model,
        ]);
    }

    /**
     * Action for reset password - user follows here by link from email message.
     * @param $token
     * @return string|Response
     * @throws BadRequestHttpException
     */
    public function actionResetPassword($token)
    {
        try {
            $model = new ResetPasswordForm($token);
        } catch (InvalidParamException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        if ($model->load(Yii::$app->request->post()) && $model->validate() && $model->resetPassword()) {
            Yii::$app->getSession()->setFlash(
                'success',
                Yii::t('app', 'New password was saved.')
            );

            return $this->goHome();
        }

        return $this->render('resetPassword', [
            'model' => $model,
        ]);
    }

    /**
     * Action for changing profile fields
     * @return string
     */
    public function actionProfile()
    {
        /** @var \app\modules\user\models\User|HasProperties $model */
        $model = User::findOne(Yii::$app->user->id);
        $model->scenario = 'updateProfile';
        $model->abstractModel->setAttrubutesValues(Yii::$app->request->post());
        if ($model->load(Yii::$app->request->post()) && $model->validate() && $model->abstractModel->validate()) {
            if ($model->save()) {
//                $model->getPropertyGroups(true);
//                $model->saveProperties(Yii::$app->request->post());
                Yii::$app->session->setFlash('success', Yii::t('app', 'Your profile has been updated'));
                $this->refresh();
            } else {
                Yii::$app->session->setFlash('error', Yii::t('app', 'Internal error'));
            }
        }
//        $propertyGroups = PropertyGroup::getForModel($model->getObject()->id, $model->id);
//        $properties = [];
//        foreach ($propertyGroups as $propertyGroup) {
//            $properties[$propertyGroup->id] = [
//                'group' => $propertyGroup,
//                'properties' => Property::getForGroupId($propertyGroup->id),
//            ];
//        }
//        unset($propertyGroups);
        return $this->render(
            'profile',
            [
                'model' => $model,
//                'propertyGroups' => $properties,
                'services' => ArrayHelper::map($model->services, 'id', 'service_type'),
            ]
        );
    }

    /**
     * Action for handling password changing
     * @return string
     * @throws NotFoundHttpException
     */
    public function actionChangePassword()
    {
        $model = User::findOne(Yii::$app->user->id);
        if (is_null($model)) {
            throw new NotFoundHttpException;
        }
        $model->scenario = 'changePassword';
        if (Yii::$app->request->isPost) {
            $model->load(Yii::$app->request->post());
            $formIsValid = $model->validate();
            $passwordIsValid = $model->validatePassword($model->password);
            if (!$passwordIsValid) {
                $model->addError('password', Yii::t('app', 'Wrong password'));
            }
            if ($formIsValid && $passwordIsValid) {
                $security = new Security;
                $model->password_hash = $security->generatePasswordHash($model->newPassword);
                if ($model->save(true, ['password_hash'])) {
                    Yii::$app->session->setFlash('success', Yii::t('app', 'Password has been changed'));
                    $this->refresh();
                } else {
                    Yii::$app->session->setFlash('error', Yii::t('app', 'Internal error'));
                }
            }
        }
        return $this->render('change-password', ['model' => $model]);
    }

}