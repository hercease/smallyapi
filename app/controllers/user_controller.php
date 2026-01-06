<?php
class UserController{
      private $db, $userModel;
      public function __construct($db){
          $this->db = $db;
          $this->userModel = new UserModel($db);
      }
      
      public function createUser(){
            $name = $_POST['name'] ?? '';
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            $phone = $_POST['phone'] ?? '';

            $requeiredFields = ['name', 'email', 'password', 'phone'];
            foreach($requeiredFields as $field){
                if(empty($_POST[$field])){
                    sendErrorResponse(400, ['errors' => [
                        'title' => 'Error',
                        'detail' => "$field is required"
                    ]]);
                    return;
                }
            }

            if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
                sendErrorResponse(400, ['errors' => [
                    'title' => 'Error',
                    'detail' => 'Email is invalid'
                ]]);
                return;
            }

            if($this->userModel->fetchuserinfo($email)) {
                sendErrorResponse(400, ['errors' => [
                    'title' => 'Error',
                    'detail' => 'Email already exists'
                ]]);
                return;
            }

            $response = $this->userModel->processUserCreation($name, $email, $password, $phone);
            $token = $this->userModel->encryptCookie($email);
            sendSuccessResponse(['status' => $response, 'token' => $token]);
      }

      public function sendVerificationCode(){
          $email = $_POST['email'] ?? '';
          $code = $_POST['code'] ?? '';
          $name = $_POST['name'] == '' ? 'User' : $_POST['name'];

          $requeiredFields = ['email', 'code'];
            foreach($requeiredFields as $field){
                if(empty($_POST[$field])){
                    sendErrorResponse(400, ['errors' => ["$field is required"]]);
                    return;
                }
            }

          $response = $this->userModel->processSendVerificationCode($email, $code, $name);
          sendSuccessResponse(['status' => $response]);
      }

    public function fetchUserData(){
        $email = isset($_POST['token']) ? $this->userModel->decryptCookie($_POST['token']) : null;
        if(!$email){
            sendErrorResponse(400, ['errors' => [
                'title' => 'Error',
                'detail' => 'Email is required'
            ]]);
            return;
        }
        $user = $this->userModel->fetchuserinfo($email);
        if($user){
            sendSuccessResponse(['user' => $user]);
        } else {
            sendErrorResponse(404, ['errors' => [
                'title' => 'Error',
                'detail' => 'User not found'
            ]]);
        }
    }

    public function loginuser(){

        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';

        $requeiredFields = ['email', 'password'];
        foreach($requeiredFields as $field){
            if(empty($_POST[$field])){
                sendErrorResponse(400, ['errors' => [
                    'title' => 'Error',
                    'detail' => "$field is required"
                ]]);
            }
        }

        $user = $this->userModel->fetchuserinfo($email);
        if(!$user || !password_verify($password, $user['password'])){
            sendErrorResponse(401, ['errors' => [
                'title' => 'Error',
                'detail' => 'Invalid email or password'
            ]]);
        }

        $token = $this->userModel->encryptCookie($email);
        sendSuccessResponse(['status' => true, 'token' => $token]);
    }

    public function UserBookings(){
        $email = isset($_POST['token']) ? $this->userModel->decryptCookie($_POST['token']) : null;
        if(!$email){
            sendErrorResponse(400, ['errors' => [
                'title' => 'Error',
                'detail' => 'Email is required'
            ]]);
            return;
        }
        $bookings = $this->userModel->fetchUserBookings($email);
        sendSuccessResponse(['bookings' => $bookings]);
    }


}