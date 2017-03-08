<?php
/**
 * @author Jason Harris <1337reloaded@gmail.com>
 * @date 2/28/2017 4:15 PM
 */

namespace Reloaded\UnrealEngine4\Web\Controllers;

use App\Library\EmailMessages\AccountRecovery\ActivateTemplate;
use App\Library\EmailMessages\AccountRecovery\RecoverPasswordTemplate;
use App\Library\Net\HttpStatusCode;
use App\Library\Net\Responses\DataObjectResponse;
use App\Library\Net\Responses\FaultResponse;
use App\Library\Net\Responses\ValidationFaultResponse;
use App\Library\Net\Responses\ValidationFieldError;
use App\Library\Requests\Account\Activate as ActivateRequest;
use App\Library\Requests\Account\Login as LoginRequest;
use App\Library\Requests\Account\RecoverPassword as RecoverPasswordRequest;
use App\Library\Requests\Account\Registration as RegistrationRequest;
use App\Library\Requests\Account\Validation\Activate as ActivateRequestValidation;
use App\Library\Requests\Account\Validation\Login as LoginRequestValidation;
use App\Library\Requests\Account\Validation\RecoverPassword as RecoverPasswordValidation;
use App\Library\Requests\Account\Validation\Registration as RegistrationRequestValidation;
use App\Models\AbstractPlayers;
use App\Models\PlayerAccountRecovery;
use App\Models\Players;
use App\Models\PlayerSessions;
use Ramsey\Uuid\Uuid;
use Zend\Mail\Message;
use Zend\Math\Rand;
use Zend\Mime\Message as MimeMessage;
use Zend\Mime\Mime;
use Zend\Mime\Part;


class AccountController extends ControllerBase
{
    #region API Endpoints

    /**
     * Registers a new player account. An account activation email will be sent to the player's email
     * address immediately.
     *
     * @param RegistrationRequest $request
     * @return \Phalcon\Http\Response|\Phalcon\Http\ResponseInterface
     * @see RegistrationRequest
     */
    public function registerAction(RegistrationRequest $request)
    {
        try
        {
            $this->db->begin();

            $requestValidation = new RegistrationRequestValidation();
            $requestErrors = $requestValidation->validate(null, $request);

            if(count($requestErrors))
            {
                $validationFault = new ValidationFaultResponse(HttpStatusCode::BadRequest);
                $validationFault->addPhalconValidationGroup($requestValidation->getMessages());

                return $this->response
                    ->setStatusCode(HttpStatusCode::BadRequest)
                    ->setJsonContent($validationFault);
            }

            #region Create Player

            $guid = Uuid::uuid4();

            $player = new Players([
                'Id' => $guid->toString(),
                'FirstName' => $request->FirstName,
                'LastName' => $request->LastName,
                'Email' => $request->Email,
                'InGameName' => $request->InGameName,
                'Password' => password_hash($request->Password, PASSWORD_DEFAULT)
            ]);

            if(!$player->save())
            {
                $this->db->rollback();

                $validationFault = new ValidationFaultResponse(HttpStatusCode::BadRequest);
                $validationFault->addPhalconModelMessages($player->getMessages());

                return $this->response
                    ->setStatusCode(HttpStatusCode::BadRequest)
                    ->setJsonContent($validationFault);
            }

            #endregion

            #region Create registration code

            $accountRecoveryDuration = new \DateTime($this->_appSettings->accountRecoveryDuration);
            $accountRecovery = new PlayerAccountRecovery([
                'PlayerId' => $player->getId(),
                'Code' => $this->_generateRecoveryCode(),
                'Expiration' => $accountRecoveryDuration->format('Y-m-d H:i:s'),
                'GeneratedOn' => (new \DateTime())->format('Y-m-d H:i:s'),
                'Type' => 'Activation'
            ]);

            if(!$accountRecovery->save())
            {
                $this->db->rollback();

                $faultResponse = new FaultResponse(
                    'There was an error with account activation.',
                    HttpStatusCode::InternalServerError
                );

                return $this->response
                    ->setStatusCode(HttpStatusCode::InternalServerError)
                    ->setJsonContent($faultResponse);
            }

            #endregion

            $this->db->commit();

            #region Send Account Activation Email

            $this->_mailTransport->send($this->_createAccountActivationMessage($player, $accountRecovery));

            #endregion

            $responseData = (object) [
                'Id' => $player->getId(),
                'FirstName' => $player->getFirstName(),
                'LastName' => $player->getLastName(),
                'Email' => $player->getEmail(),
                'InGameName' => $player->getInGameName()
            ];

            return $this->response
                ->setStatusCode(HttpStatusCode::Created)
                ->setJsonContent(new DataObjectResponse($responseData, HttpStatusCode::Created));
        }
        catch(\Exception $ex)
        {
            if($this->db->isUnderTransaction())
            {
                $this->db->rollback();
            }

            return $this->response
                ->setStatusCode(HttpStatusCode::InternalServerError)
                ->setJsonContent(new FaultResponse(
                    'There was an unexpected error while registering your account.',
                    HttpStatusCode::InternalServerError
                ));
        }
    }

    /**
     * Activates a new player account that has not yet been activated.
     *
     * @param ActivateRequest $request
     * @return \Phalcon\Http\Response|\Phalcon\Http\ResponseInterface
     * @see RegistrationRequest
     */
    public function activateAction(ActivateRequest $request)
    {
        try
        {
            $this->db->begin();

            $requestValidation = new ActivateRequestValidation();
            $requestErrors = $requestValidation->validate(null, $request);

            if(count($requestErrors))
            {
                $this->db->rollback();

                $validationFault = new ValidationFaultResponse(HttpStatusCode::BadRequest);
                $validationFault->addPhalconValidationGroup($requestValidation->getMessages());

                return $this->response
                    ->setStatusCode(HttpStatusCode::BadRequest)
                    ->setJsonContent($validationFault);
            }

            #region Lookup player by email and password

            $player = Players::findFirst([
                'conditions' => 'Email = ?1',
                'bind' => [
                    1 => $request->Email
                ]
            ]);

            if(!$player || !password_verify($request->Password, $player->getPassword()))
            {
                $this->db->rollback();

                $validationFault = new ValidationFaultResponse(HttpStatusCode::BadRequest);
                $validationFault->addValidationErrors([new ValidationFieldError(
                    'Email_Or_Password',
                    'The email and password you entered does not match our records.'
                )]);

                return $this->response
                    ->setStatusCode(HttpStatusCode::BadRequest)
                    ->setJsonContent($validationFault);
            }

            #endregion

            #region Lookup player activation code

            $playerActivation = PlayerAccountRecovery::findFirst([
                'conditions' => 'PlayerId = ?1 AND Code = ?2 AND Expiration >= ?3 AND Type = ?4 ',
                'bind' => [
                    1 => Uuid::fromString($player->getId())->getBytes(),
                    2 => $request->Code,
                    3 => (new \DateTime())->format('Y-m-d H:i:s'),
                    4 => 'Activation'
                ]
            ]);

            if(!$playerActivation)
            {
                $this->db->rollback();

                $validationFault = new ValidationFaultResponse(HttpStatusCode::BadRequest);
                $validationFault->addValidationErrors([new ValidationFieldError(
                    'Code_Or_Expiration',
                    'Activation code does not match our records for this account or it has expired.'
                )]);

                return $this->response
                    ->setStatusCode(HttpStatusCode::BadRequest)
                    ->setJsonContent($validationFault);
            }

            #endregion

            #region Activate player

            $player->setIsActivated(true);

            if(!$player->save())
            {
                $this->db->rollback();

                $validationFault = new ValidationFaultResponse(HttpStatusCode::BadRequest);
                $validationFault->addPhalconModelMessages($player->getMessages());

                return $this->response
                    ->setStatusCode(HttpStatusCode::BadRequest)
                    ->setJsonContent($validationFault);
            }

            #endregion

            #region Delete player activation code

            if(!$playerActivation->delete())
            {
                $this->db->rollback();

                $faultResponse = new FaultResponse(
                    'There was an error deleting the account activation code.',
                    HttpStatusCode::InternalServerError
                );

                return $this->response
                    ->setStatusCode(HttpStatusCode::InternalServerError)
                    ->setJsonContent($faultResponse);
            }

            #endregion

            #region Create player session

            $playerSession = $this->_recreatePlayerSession($player);

            if(!$playerSession->save())
            {
                $this->db->rollback();

                $validationFault = new ValidationFaultResponse(HttpStatusCode::BadRequest);
                $validationFault->addPhalconModelMessages($playerSession->getMessages());

                return $this->response
                    ->setStatusCode(HttpStatusCode::BadRequest)
                    ->setJsonContent($validationFault);
            }

            #endregion

            $this->db->commit();

            $responseData = (object) [
                'SessionId' => $playerSession->getSessionId(),
                'Expiration' => $playerSession->getExpiration()
            ];

            return $this->response
                ->setStatusCode(HttpStatusCode::Created)
                ->setJsonContent(new DataObjectResponse($responseData, HttpStatusCode::Created));
        }
        catch(\Exception $ex)
        {
            if($this->db->isUnderTransaction())
            {
                $this->db->rollback();
            }

            return $this->response
                ->setStatusCode(HttpStatusCode::InternalServerError)
                ->setJsonContent(new FaultResponse(
                    'There was an unexpected error while activating your account.',
                    HttpStatusCode::InternalServerError
                ));
        }
    }

    /**
     * Authenticates an existing player and create a new session. If there is an existing session for the player its
     * invalidated and a new one is created.
     *
     * @param LoginRequest $request
     * @return \Phalcon\Http\Response|\Phalcon\Http\ResponseInterface
     */
    public function loginAction(LoginRequest $request)
    {
        try
        {
            $this->db->begin();

            $requestValidation = new LoginRequestValidation();
            $requestErrors = $requestValidation->validate(null, $request);

            if(count($requestErrors))
            {
                $validationFault = new ValidationFaultResponse(HttpStatusCode::BadRequest);
                $validationFault->addPhalconValidationGroup($requestValidation->getMessages());

                return $this->response
                    ->setStatusCode(HttpStatusCode::BadRequest)
                    ->setJsonContent($validationFault);
            }

            #region Lookup player by email and password

            $player = Players::findFirst([
                'conditions' => 'Email = ?1',
                'bind' => [
                    1 => $request->Email
                ]
            ]);

            if(!$player || !password_verify($request->Password, $player->getPassword()))
            {
                $validationFault = new ValidationFaultResponse(HttpStatusCode::BadRequest);
                $validationFault->addValidationErrors([new ValidationFieldError(
                    'Email_Or_Password',
                    'The email and password you entered does not match our records.'
                )]);

                return $this->response
                    ->setStatusCode(HttpStatusCode::BadRequest)
                    ->setJsonContent($validationFault);
            }

            #endregion

            #region Check player activated

            if(!$player->getIsActivated())
            {
                $faultResponse = new FaultResponse(
                    'You must activate your account before logging in.',
                    HttpStatusCode::Unauthorized
                );

                return $this->response
                    ->setStatusCode(HttpStatusCode::Unauthorized)
                    ->setJsonContent($faultResponse);
            }

            #endregion

            #region Create player session

            $playerSession = $this->_recreatePlayerSession($player);

            if(!$playerSession->save())
            {
                $this->db->rollback();

                $validationFault = new ValidationFaultResponse(HttpStatusCode::BadRequest);
                $validationFault->addPhalconModelMessages($playerSession->getMessages());

                return $this->response
                    ->setStatusCode(HttpStatusCode::BadRequest)
                    ->setJsonContent($validationFault);
            }

            #endregion

            $this->db->commit();

            $responseData = (object) [
                'SessionId' => $playerSession->getSessionId(),
                'Expiration' => $playerSession->getExpiration()
            ];

            return $this->response
                ->setStatusCode(HttpStatusCode::Created)
                ->setJsonContent(new DataObjectResponse($responseData, HttpStatusCode::Created));
        }
        catch(\Exception $ex)
        {
            if($this->db->isUnderTransaction())
            {
                $this->db->rollback();
            }

            return $this->response
                ->setStatusCode(HttpStatusCode::InternalServerError)
                ->setJsonContent(new FaultResponse(
                    'There was an unexpected error while authenticating.',
                    HttpStatusCode::InternalServerError
                ));
        }
    }

    public function resetPasswordAction()
    {

    }

    /**
     * Starts the password reset process by creating a password reset recovery and sends an email to the player
     * with a reset code.
     *
     * If the player account has not been activated yet this will fail and a 417 Expectation Failed response returned.
     *
     * @param RecoverPasswordRequest $request
     * @return \Phalcon\Http\Response|\Phalcon\Http\ResponseInterface
     */
    public function recoverPasswordAction(RecoverPasswordRequest $request)
    {
        try
        {
            $this->db->begin();

            $requestValidation = new RecoverPasswordValidation();
            $requestErrors = $requestValidation->validate(null, $request);

            if(count($requestErrors))
            {
                $validationFault = new ValidationFaultResponse(HttpStatusCode::BadRequest);
                $validationFault->addPhalconValidationGroup($requestValidation->getMessages());

                return $this->response
                    ->setStatusCode(HttpStatusCode::BadRequest)
                    ->setJsonContent($validationFault);
            }

            #region Lookup player by email

            /** @var Players $player */
            $player = Players::findFirst([
                'conditions' => 'Email = ?1',
                'bind' => [
                    1 => $request->Email
                ]
            ]);

            if(!$player)
            {
                $validationFault = new ValidationFaultResponse(HttpStatusCode::BadRequest);
                $validationFault->addValidationErrors([new ValidationFieldError(
                    'Email',
                    'The email you entered does not match our records.'
                )]);

                return $this->response
                    ->setStatusCode(HttpStatusCode::BadRequest)
                    ->setJsonContent($validationFault);
            }

            #endregion

            #region Check player activated

            if(!$player->getIsActivated())
            {
                $faultResponse = new FaultResponse(
                    'Oh no! Looks like you haven\'t activated your account yet.',
                    HttpStatusCode::ExpectationFailed
                );

                return $this->response
                    ->setStatusCode(HttpStatusCode::ExpectationFailed)
                    ->setJsonContent($faultResponse);
            }

            #endregion

            #region Create password reset code

            $accountRecoveryDuration = new \DateTime($this->_appSettings->accountRecoveryDuration);
            $accountRecovery = new PlayerAccountRecovery([
                'PlayerId' => $player->getId(),
                'Code' => $this->_generateRecoveryCode(),
                'Expiration' => $accountRecoveryDuration->format('Y-m-d H:i:s'),
                'GeneratedOn' => (new \DateTime())->format('Y-m-d H:i:s'),
                'Type' => 'PasswordReset'
            ]);

            if(!$accountRecovery->save())
            {
                $this->db->rollback();

                $faultResponse = new FaultResponse(
                    'There was an error creating a password reset code.',
                    HttpStatusCode::InternalServerError
                );

                return $this->response
                    ->setStatusCode(HttpStatusCode::InternalServerError)
                    ->setJsonContent($faultResponse);
            }

            #endregion

            $this->db->commit();

            #region Send email

            $this->_mailTransport->send($this->_createPasswordResetMessage($player, $accountRecovery));

            #endregion

            return $this->response
                ->setStatusCode(HttpStatusCode::OK);
        }
        catch(\Exception $ex)
        {
            if($this->db->isUnderTransaction())
            {
                $this->db->rollback();
            }

            return $this->response
                ->setStatusCode(HttpStatusCode::InternalServerError)
                ->setJsonContent(new FaultResponse(
                    'There was an unexpected error.',
                    HttpStatusCode::InternalServerError
                ));
        }
    }

    #endregion

    /**
     * Creates a \Zend\Mail\Message for the account activation email.
     *
     * @param Players $player
     * @param PlayerAccountRecovery $accountRecovery
     * @return Message
     */
    private function _createAccountActivationMessage(Players $player, PlayerAccountRecovery $accountRecovery): Message
    {
        $emailTemplate = new ActivateTemplate($player, $accountRecovery);

        $html = new Part($emailTemplate->renderHtml());
        $html->type = Mime::TYPE_HTML;
        $html->charset = 'utf-8';
        $html->encoding = Mime::ENCODING_QUOTEDPRINTABLE;

        $body = new MimeMessage();
        $body->setParts([$html]);

        $activationMessage = new Message();
        $activationMessage
            ->addTo($player->getEmail())
            ->addFrom($this->di->getShared('config')->application->noReplyEmail)
            ->setSubject(sprintf(
                'Your new account with %s',
                $this->di->getShared('config')->application->siteName
            ))
            ->setBody($body);

        return $activationMessage;

    }

    /**
     * Creates a \Zend\Mail\Message for the account password reset email.
     *
     * @param Players $player
     * @param PlayerAccountRecovery $accountRecovery
     * @return Message
     */
    private function _createPasswordResetMessage(Players $player, PlayerAccountRecovery $accountRecovery): Message
    {
        $emailTemplate = new RecoverPasswordTemplate($player, $accountRecovery);

        $html = new Part($emailTemplate->renderHtml());
        $html->type = Mime::TYPE_HTML;
        $html->charset = 'utf-8';
        $html->encoding = Mime::ENCODING_QUOTEDPRINTABLE;

        $body = new MimeMessage();
        $body->setParts([$html]);

        $message = new Message();
        $message
            ->addTo($player->getEmail())
            ->addFrom($this->di->getShared('config')->application->noReplyEmail)
            ->setSubject('Reset your password')
            ->setBody($body);

        return $message;

    }

    /**
     * Creates a new player session if one does not already exist for this player otherwise retrieves the existing
     * session and updates it with new values.
     *
     * @param AbstractPlayers $player
     * @return PlayerSessions
     */
    private function _recreatePlayerSession(AbstractPlayers $player): PlayerSessions
    {

        $playerSession = PlayerSessions::findFirst([
            'conditions' => 'PlayerId = ?1',
            'bind' => [
                1 => Uuid::fromString($player->getId())->getBytes()
            ]
        ]);

        if(!$playerSession)
        {
            $playerSession = new PlayerSessions([
                'PlayerId' => $player->getId()
            ]);
        }

        $sessionExpiration = new \DateTime($this->di->getShared('config')->application->sessionDuration);

        $playerSession
            ->setSessionId(Uuid::uuid4()->toString())
            ->setExpiration($sessionExpiration->format('Y-m-d H:i:s'))
            ->setCreated((new \DateTime())->format('Y-m-d H:i:s'))
            ->setRemoteIp($this->request->getClientAddress());

        return $playerSession;

    }

    /**
     * Generates a 10 character alphanumeric account recovery code.
     *
     * @return string
     */
    private function _generateRecoveryCode(): string
    {
        return Rand::getString(10, 'abcdefghijklmnopqrstuvwxyz0123456789');
    }
}

