<?php
use PHPMailer\PHPMailer\PHPMailer;
class UserModel {
    private $db;
    public function __construct($db) {
        $this->db = $db;
    }

    public function sanitizeInput($data) {
        if (is_array($data)) {
            // Loop through each element of the array and sanitize recursively
            foreach ($data as $key => $value) {
                $data[$key] = $this->sanitizeInput($value);
            }

        } else {
            // If it's not an array, sanitize the string
            $data = trim($data); // Remove unnecessary spaces
            $data = stripslashes($data); // Remove backslashes
            $data = htmlspecialchars($data); // Convert special characters to HTML entities
        }

        return $data;
    }

    public function processUserCreation($name, $email, $password, $phone) {
        $stmt = $this->db->prepare("INSERT INTO api_users (name, email, password, reg_date, phone) VALUES (?, ?, ?, ?, ?)");
        return $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s'), $phone]);
    }

     public function InsertBookings($email, $firstname, $lastname, $reference, $phone, $date, $status, $type, $booking_type, $user, $payment_type, $payment_method, $total_amount) {
        $sql = "INSERT INTO flight_bookings (email, firstname, lastname, reference, phone, date, status, type, booking_type, user, payment_type, payment_method, total_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("sssssssss", $email, $firstname, $lastname, $reference, $phone, $date, $status, $type, $booking_type, $user, $payment_type, $payment_method, $total_amount);
        if (!$stmt->execute()) {
            throw new Exception("Failed to insert booking: " . $stmt->error);
        }
        return $this->db->insert_id;
    }

    public function removeFromCartByUser($user_id,$cart_item_id) {
        $sql = "DELETE FROM cart WHERE user_id = ? AND cart_item_id = ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->db->error);
        }
        $stmt->bind_param("is", $user_id, $cart_item_id);
        
        if ($stmt->execute()) {
            return $stmt->affected_rows > 0;
        } else {
            throw new Exception("Failed to remove cart items: " . $stmt->error);
        }
    }

     public function removeFromCartBySession($session_id,$cart_item_id) {
        $sql = "DELETE FROM cart WHERE session_id = ? AND cart_item_id = ? AND user_id IS NULL";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->db->error);
        }
        $stmt->bind_param("ss", $session_id, $cart_item_id);
        
        if ($stmt->execute()) {
            return $stmt->affected_rows > 0;
        } else {
            throw new Exception("Failed to remove cart items: " . $stmt->error);
        }
    }


    public function getCartItemById($cart_id) {
        try {
            $sql = "SELECT * FROM cart WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            $stmt->bind_param("i", $cart_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $cartItem = $result->fetch_assoc();
            $stmt->close();

            // Process JSON fields to remove slashes
            if ($cartItem) {
                if (isset($cartItem['room_data'])) {
                    $cartItem['room_data'] = $this->cleanJsonField($cartItem['room_data']);
                }
                if (isset($cartItem['rate_data'])) {
                    $cartItem['rate_data'] = $this->cleanJsonField($cartItem['rate_data']);
                }
                if (isset($cartItem['booking_details'])) {
                    $cartItem['booking_details'] = $this->cleanJsonField($cartItem['booking_details']);
                }
                $cartItem['added_at'] = $cartItem['added_at'];
                $cartItem['expires_at'] = $cartItem['expires_at'];
            }

            //error_log("Cart item with hotel info: " . json_encode($cartItem));

            return $cartItem ?? null;

        } catch (Exception $e) {
            error_log("Error in getCartItemById: " . $e->getMessage());
            return null;
        }
    }

    
    public function checkUser($user_id, $session_id){

        if (!isset($user_id) || $user_id === null || $user_id === '' || strtolower($user_id) === 'null') {
            return ["userid" => null, "session_id" => $session_id];
        }

        $decrypted = $this->decryptCookie($this->sanitizeInput($user_id));
        $userid = $this->fetchuserinfo($decrypted)['id'];
        $this->transferGuestCartToUser($userid, $session_id);

        return ["userid" => $userid, "session_id" => null];
    }

     public function addToCart($params) {
        try {
            // Validate required data
            $cart_item_id = $params['cartItemId'] ?? null;
            $room_data = $params['roomData'] ?? null;
            $rate_data = $params['rateData'] ?? null;
            $booking_details = $params['bookingDetails'] ?? null;
            $session_id = $params['session_id'] ?? null;
            $user_id = $this->sanitizeInput($params['user_id']) ?? null;
            $timezone = $params['timezone'] ?? 'Africa/Lagos';
            date_default_timezone_set($timezone);

            if (!$cart_item_id || !$room_data || !$rate_data || !$booking_details) {
                throw new Exception('Missing required cart data');
            }

            $user = $this->checkUser($user_id, $session_id);
            $user_check_id = $user['userid'];
            $user_check_session = $user['session_id'];
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON data provided');
            }
            
            // Check if item already exists in cart
            if(!$user_check_id || $user_check_id === null || $user_check_id === '' || strtolower($user_check_id) === 'null'){
                $stmt = $this->db->prepare("SELECT * FROM cart WHERE cart_item_id = ? AND session_id = ?");
                $stmt->bind_param("ss", $cart_item_id, $user_check_session);
            }else{
                $stmt = $this->db->prepare("SELECT * FROM cart WHERE cart_item_id = ? AND user_id = ?");
                $stmt->bind_param("si", $cart_item_id, $user_check_id);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Update existing item
                $message = 'Item already exists in cart';
                $is_new = false;
            } else {
                // Add new item
                $this->insertCartItem($cart_item_id, $user_check_id, $user_check_session, $room_data, $rate_data, $booking_details);
                $message = 'Item added to cart successfully';
                $is_new = true;
            }
            
            return [
                'success' => true,
                'message' => $message,
                'cart_item_id' => $cart_item_id,
                'is_new_item' => $is_new
            ];
            
        } catch (Exception $e) {
            error_log("Cart error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function getCartItem($user_id, $session_id)
    {
        try {

            // Determine if user is logged in
            $isLoggedIn = isset($user_id) && $user_id !== '' && $user_id !== null && strtolower($user_id) !== 'null' && $user_id != 0;

            // Prepare SQL dynamically based on user type
            if ($isLoggedIn) {
                $sql = "SELECT * FROM cart WHERE user_id = ? AND expires_at > NOW()";
            } else {
                $sql = "SELECT * FROM cart WHERE session_id = ? AND user_id IS NULL AND expires_at > NOW()";
            }

            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }

            // Bind correct parameters
            if ($isLoggedIn) {
                $stmt->bind_param("i", $user_id);
            } else {
                $stmt->bind_param("s", $session_id);
            }

            // Execute query
            $stmt->execute();
            $result = $stmt->get_result();

            // Fetch single row
            $cartItems = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

             // Process JSON fields to remove slashes
            foreach ($cartItems as &$item) {
                if (isset($item['room_data'])) {
                    $item['room_data'] = $this->cleanJsonField($item['room_data']);
                }
                if (isset($item['rate_data'])) {
                    $item['rate_data'] = $this->cleanJsonField($item['rate_data']);
                }
                if (isset($item['booking_details'])) {
                    $item['booking_details'] = $this->cleanJsonField($item['booking_details']);
                }
            }

            return  [
                        'cart_items' => $cartItems ?? [],
                        'success' => true
                    ];

        } catch (Exception $e) {
            error_log("Error in getCartItem: " . $e->getMessage());
            return null;
        }
    }


    public function transferGuestCartToUser($user_id, $session_id) {
        $select = $this->db->prepare("SELECT * FROM cart WHERE session_id = ? AND user_id IS NULL");
        $select->bind_param("s", $session_id);
        $select->execute();
        $result = $select->get_result();
        if ($result->num_rows > 0) {
            $sql = "UPDATE cart SET user_id = ?, session_id = NULL WHERE session_id = ? AND user_id IS NULL";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("ss", $user_id, $session_id);
            $stmt->execute();
        }
    }


    private function insertCartItem($cart_item_id, $user_id, $session_id, $room_data, $rate_data, $booking_details)
    {
        // Determine if user is logged in
        $isLoggedIn = isset($user_id) && $user_id !== '' && $user_id !== null && strtolower($user_id) !== 'null' && $user_id != 0;

        // Prepare SQL with placeholders for user_id/session_id
        $sql = "INSERT INTO cart (
            cart_item_id, 
            user_id, 
            session_id, 
            room_data, 
            rate_data, 
            booking_details, 
            added_at, 
            expires_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 15 MINUTE))";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->db->error);
        }

        // Encode data to JSON
        $json_room_data = $room_data;
        $json_rate_data = $rate_data;
        $json_booking_details = $booking_details;

        // Assign values depending on user type
        $userValue = $isLoggedIn ? $user_id : null;
        $sessionValue = $isLoggedIn ? null : $session_id;

        // Bind parameters
        $stmt->bind_param(
            "sissss",
            $cart_item_id,
            $userValue,
            $sessionValue,
            $json_room_data,
            $json_rate_data,
            $json_booking_details
        );

        // Execute and handle result
        if (!$stmt->execute()) {
            throw new Exception("Failed to insert cart item: " . $stmt->error);
        }

        return $this->db->insert_id;
    }


   public function removeFromCart($cart_id) {
        
            // User is logged in - delete by user_id
            $sql = "DELETE FROM cart WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            $stmt->bind_param("i", $cart_id);
        
        
        if ($stmt->execute()) {

            return [
                'success' => $stmt->affected_rows > 0
            ];

        } else {
            throw new Exception("Failed to remove cart item: " . $stmt->error);
        }
    }

    public function random_string($length){
		return substr(bin2hex(random_bytes($length)), 0, $length);
	}

    public function encryptCookie($value){

		$byte = $this->random_string(20);
		$key = hex2bin($byte);

		$cipher = "AES-256-CBC";
		$ivlen = openssl_cipher_iv_length($cipher);
		$iv = openssl_random_pseudo_bytes($ivlen);

		$ciphertext = openssl_encrypt($value, $cipher, $key, 0, $iv);

		return( base64_encode($ciphertext . '::' . $iv. '::' .$key) );
	}

	// Decrypt cookie
	function decryptCookie($ciphertext){
		$cipher = "AES-256-CBC";
		list($encrypted_data, $iv,$key) = explode('::', base64_decode($ciphertext));
		return openssl_decrypt($encrypted_data, $cipher, $key, 0, $iv);
	}

    public function fetchuserinfo($email) {
		$sql = "SELECT * FROM api_users WHERE email = ? OR token = ? LIMIT 1";
		$stmt = $this->db->prepare($sql);
		$stmt->bind_param("ss", $email, $email);
		$stmt->execute();
		
		$result = $stmt->get_result();
		$row = $result->fetch_assoc();

		$stmt->close();
		return $row;

	}

    public function updateWalletBalance($token, $new_balance) {
        $sql = "UPDATE api_users SET wallet = wallet - ? WHERE token = ? OR email = ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->db->error);
        }
        $stmt->bind_param("di", $new_balance, $token, $token);
        
        if ($stmt->execute()) {
            return $stmt->affected_rows > 0;
        } else {
            throw new Exception("Failed to update wallet balance: " . $stmt->error);
        }
    }

    public function calculatePercentage($total, $percentage) {
        if ($percentage <= 0) {
            return 0;
        }
        return ($percentage / 100) * $total;
    }

    public function isValidApiKey(string $apiKey): bool 
    {
        $stmt = $this->db->prepare("SELECT 1 FROM api_users WHERE token = ? LIMIT 1");
        if (!$stmt) {
            error_log("Prepare failed: " . $this->db->error);
            return false;
        }
    
        $stmt->bind_param("s", $apiKey);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
    
        return $exists;
    }

    public function sendmail($email,$name,$body,$subject){

        require_once 'PHPMailer/src/Exception.php';
        require_once 'PHPMailer/src/PHPMailer.php';
        require_once 'PHPMailer/src/SMTP.php';

        $mail = new PHPMailer(true);
        
        try {
            
            $mail->isSMTP();                           
            $mail->Host       = SMTP_HOST;      
            $mail->SMTPAuth   = true;
            $mail->SMTPKeepAlive = true; //SMTP connection will not close after each email sent, reduces SMTP overhead	
            $mail->Username   = SMTP_USERNAME;    
            $mail->Password   = SMTP_PASSWORD;             
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;   
            $mail->Port       = 465;               
    
            //Recipients
            $mail->setFrom(SMTP_FROM_EMAIL, 'SmallyFares Ltd'); // Sender's email and name
            $mail->addAddress("$email", "$name"); 
            
            $mail->isHTML(true); 
            $mail->Subject = $subject;
            $mail->Body    = $body;
    
            $mail->send();
            $mail->clearAddresses();
            //return true;
            
        } catch (Exception $e){
            return "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
    }

    
    private function cleanJsonField($jsonString)
    {
        // Remove slashes and decode JSON
        $cleaned = stripslashes($jsonString);
        
        // If it's still a string, try to decode it
        if (is_string($cleaned)) {
            $decoded = json_decode($cleaned, true);
            // If decoding was successful, return the decoded array
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        
        // If all else fails, return the original cleaned string
        return $cleaned;
    }

    public function processSendVerificationCode($email, $code, $name) {
        $subject = 'Verify Your Email - Smallyfares Ltd';
    
        // Simple HTML email
        $message = '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; background: #f5f7fa; padding: 20px; }
                .container { max-width: 600px; margin: auto; background: white; border-radius: 10px; overflow: hidden; }
                .header { background: #1e3a8a; color: white; padding: 30px; text-align: center; }
                .content { padding: 30px; }
                .code { font-size: 36px; font-weight: bold; color: #1e3a8a; text-align: center; margin: 20px 0; }
                .footer { background: #f8fafc; padding: 20px; text-align: center; color: #666; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>Smallyfares</h1>
                    <h2>Email Verification</h2>
                </div>
                <div class="content">
                    <h3>Hello ' . htmlspecialchars($name) . '!</h3>
                    <p>Your verification code is:</p>
                    <div class="code">' . $code . '</div>
                    <p>This code will expire in 5 minutes.</p>
                    <p><strong>Do not share this code with anyone.</strong></p>
                </div>
                <div class="footer">
                    <p>© ' . date('Y') . ' Smallyfares Ltd. All rights reserved.</p>
                    <p>Need help? Contact support@smallyfares.com</p>
                </div>
            </div>
        </body>
        </html>
        ';

        $this->sendmail($email, $name, $message, $subject);

        return true;
    }

    public function createStripePayment($amount, $currency) {
        $stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);
        $paymentIntent = $stripe->paymentIntents->create([
            'amount' => $amount,
            'currency' => $currency,
            'automatic_payment_methods' => [
                'enabled' => true,
            ]
        ]);
        //error_log("Payment intent created: " . $paymentIntent->client_secret);
        return ['paymentIntentId' => $paymentIntent->id, 'clientSecret' => $paymentIntent->client_secret];
    }

    public function InsertWalletTransactions($user_id, $amount, $type, $description, $commission, $date) {
        $sql = "INSERT INTO wallet_transactions (user_id, amount, type, description, commission, date) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("idssds", $user_id, $amount, $type, $description, $commission, $date);
        if (!$stmt->execute()) {
            throw new Exception("Failed to insert wallet transaction: " . $stmt->error);
        }
        return $this->db->insert_id;
    }

    public function confirmStripePayment($paymentIntentId) {
        try {
            $stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);
            $paymentIntent = $stripe->paymentIntents->retrieve($paymentIntentId);
            
            // Determine if payment is successful
            $isSuccessful = in_array($paymentIntent->status, ['succeeded', 'processing']);
            
            return [
                'success' => $isSuccessful,
                'status' => $paymentIntent->status,
                'payment_intent_id' => $paymentIntent->id,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
                'requires_action' => $paymentIntent->status === 'requires_action' || 
                                $paymentIntent->status === 'requires_confirmation',
                'client_secret' => $paymentIntent->client_secret,
                'last_payment_error' => $paymentIntent->last_payment_error ? [
                    'code' => $paymentIntent->last_payment_error->code,
                    'message' => $paymentIntent->last_payment_error->message
                ] : null
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'status' => 'error',
                'message' => $e->getMessage(),
                'payment_intent_id' => $paymentIntentId
            ];
        }
    }

    public function fetchUserBookings($email) {
        $sql = "SELECT * FROM flight_bookings WHERE email = ? ORDER BY date DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        
        $result = $stmt->get_result();
        $bookings = [];
        while ($row = $result->fetch_assoc()) {
            $bookings[] = $row;
        }

        $stmt->close();
        return $bookings;
    }
}