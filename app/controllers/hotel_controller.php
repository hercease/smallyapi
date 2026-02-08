<?php
class HotelController{
    private $db;
    private $hotelModel;
    private $userModels;

    public function __construct($db){
        $this->db = $db;
        $this->hotelModel = new Hotelmodels($db);
        $this->userModels = new UserModel($db);
    }


    public function searchHotelDestinations(){
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            sendErrorResponse(405, [
                'errors' => [
                    [
                        'title' => 'Method Not Allowed',
                        'detail' => 'The requested method is not allowed for this endpoint.'
                    ]
                ]
            ]);
        }

        // Get Authorization header
          // --- Get Authorization header safely ---
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        //error_log(print_r($headers, true)); // Log all headers for debugging
        $authorization = $headers['Authorization'] 
            ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null) 
            ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);

        $apiKey = $authorization ? str_replace('Bearer ', '', $authorization) : '';
    
        if (empty($apiKey)) {
            sendErrorResponse(401,[
                'errors' => [
                    [
                        'title' => 'Unauthorized',
                        'detail' => 'Authorization header is required.'
                    ]
                ]
            ]);
        }

        if (!$this->userModels->isValidApiKey($apiKey)) {
            sendErrorResponse(403, [
                'errors' => [
                    [
                        'title' => 'Forbidden',
                        'detail' => 'Invalid API key.'
                    ]
                ]
            ]);
        }

        if (isset($_GET['request_type']) && isset($_GET['q'])) {
            //error_log(print_r($_GET, true)); // Log the GET parameters for debugging
            $requestType = $_GET['request_type'];
            $search = trim($_GET['q']);
        
            if (!empty($search)) {

                $searchdestination = $this->hotelModel->searchHotelsOrDestinations($search, $requestType);
                sendSuccessResponse($searchdestination);
                
            } else {

                sendSuccessResponse(['result' => []]);

            }
        }
        
    }

        
        public function searchHotels()
        {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendErrorResponse(405,[
                    'errors' => [
                        [
                            'title' => 'Method Not Allowed',
                            'detail' => 'The requested method is not allowed for this endpoint.'
                        ]
                    ]
                ]);
            }

            // Get Authorization header
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            $authorization = $headers['Authorization'] 
            ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null) 
            ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);

            $apiKey = $authorization ? str_replace('Bearer ', '', $authorization) : '';

            if (empty($apiKey)) {
                sendErrorResponse(401, [
                    'errors' => [
                        [
                            'title' => 'Unauthorized',
                            'detail' => 'Authorization header is required.'
                        ]
                    ]
                ]);
            }

            if (!$this->userModels->isValidApiKey($apiKey)){
                sendErrorResponse(403, [
                    'errors' => [
                        [
                            'title' => 'Forbidden',
                            'detail' => 'Invalid API key.'
                        ]
                    ]
                ]);
            }

            // Parse input data
            $input = [];
            
            // Validate required fields
            $requiredFields = ['destination', 'checkIn', 'checkOut'];
            foreach ($requiredFields as $field) {
                $input[$field] = $this->userModels->sanitizeInput($_POST[$field] ?? '');
                if (empty($input[$field])) {
                    sendErrorResponse(400, [
                        'errors' => [
                            [
                                'title' => 'Bad Request',
                                'detail' => "The field '$field' is required."
                            ]
                        ]
                    ]);
                }
            }

            // Extract room configurations
            $rooms = isset($_POST['rooms']) ? (int)$_POST['rooms'] : 1;
            $occupancies = $this->hotelModel->buildOccupancy($_POST);

            if (empty($occupancies)) {
                sendErrorResponse(400, [
                    'errors' => [
                        [
                            'title' => 'Bad Request',
                            'detail' => "At least one room configuration is required."
                        ]
                    ]
                ]);
            }

            // Extract other optional fields
            $destination = $input['destination'];
            $checkIn = $input['checkIn'];
            $checkOut = $input['checkOut'];
            $minRate = isset($_POST['minRate']) && (float)$_POST['minRate'] > 0 ? (float)$_POST['minRate'] : null;
            $maxRate = isset($_POST['maxRate']) && (float)$_POST['maxRate'] > 0 ? (float)$_POST['maxRate'] : null;
            $minCategory = isset($_POST['minCategory']) ? (int)$_POST['minCategory'] : null;
            $maxCategory = isset($_POST['maxCategory']) ? (int)$_POST['maxCategory'] : null;
            $maxRooms = isset($_POST['maxRooms']) ? (int)$_POST['maxRooms'] : null;
            $minRating = isset($_POST['minRating']) ? (float)$_POST['minRating'] : null;
            $maxRating = isset($_POST['maxRating']) ? (float)$_POST['maxRating'] : null;
            $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
            $pageSize = isset($_POST['pageSize']) ? intval($_POST['pageSize']) : 6;
            $accommodations = isset($_POST['accommodations']) && is_array($_POST['accommodations']) ? $_POST['accommodations'] : null;

            // Call the model to search for hotels
            try {
                $results = $this->hotelModel->search(
                    $apiKey,
                    $destination,
                    $checkIn,
                    $checkOut,
                    $occupancies, // Pass array of room configurations instead of individual parameters
                    $minRate,
                    $maxRate,
                    $minCategory,
                    $maxCategory,
                    $maxRooms,
                    $minRating,
                    $maxRating,
                    $accommodations,
                    $rooms,
                    $page,
                    $pageSize
                );
            
                sendSuccessResponse($results);

            } catch (InvalidArgumentException $e) {
                sendErrorResponse(500, [
                    'success' => false,
                    'errors' => [
                        [
                            'title' => 'Internal Server Error',
                            'detail' => $e->getMessage()
                        ]
                    ],
                    'data' => [],
                    'pagination' => [
                        'current_page' => $page,
                        'page_size' => $pageSize,
                        'total_items' => 0,
                        'total_pages' => 0,
                        'has_next' => false,
                        'has_prev' => false
                    ]
                ]);
            } catch (RuntimeException $e) {
                sendErrorResponse(500, [
                    'success' => false,
                    'errors' => [
                        [
                            'title' => 'Internal Server Error',
                            'detail' => $e->getMessage()
                        ]
                    ],
                    'data' => [],
                    'pagination' => [
                        'current_page' => $page,
                        'page_size' => $pageSize,
                        'total_items' => 0,
                        'total_pages' => 0,
                        'has_next' => false,
                        'has_prev' => false
                    ]
                ]);
            }
        }

        public function allAccommodations() {
        
            if ($_SERVER['REQUEST_METHOD'] !== 'GET') {

                sendErrorResponse(405, [
                    'errors' => [
                        'title' => 'Method Not Allowed',
                        'detail' => 'The requested method is not allowed for this endpoint.'
                    ]
                ]);
                 
            }
        
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            //error_log(print_r($headers, true)); // Log all headers for debugging
            $authorization = $headers['Authorization'] 
                ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null) 
                ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);

            $apiKey = $authorization ? str_replace('Bearer ', '', $authorization) : '';
        
            if (empty($apiKey)) {

                sendErrorResponse(401, [
                        'errors' => [
                            'title' => 'Unauthorized',
                            'detail' => 'Authorization header is required.'
                        ]
                ]);
                
            }

            if (!$this->userModels->isValidApiKey($apiKey)) {
                sendErrorResponse(403, [
                    'errors' => [
                        [
                            'title' => 'Forbidden',
                            'detail' => 'Invalid API key.'
                        ]
                    ]
                ]);
            }
        
            try {

                $results = $this->hotelModel->fetchAccommodations();
                sendSuccessResponse(['data' => $results]);

            } catch (\Throwable $th) {

                sendErrorResponse(500, [
                    'errors' => [
                        'title' => 'Internal Server Error',
                        'detail' => $th->getMessage()
                    ]
                ]);

            }
        }

        public function Hoteldetails(){
            
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendErrorResponse(405, [
                    'errors' => [
                        [
                            'title' => 'Method Not Allowed',
                            'detail' => 'The requested method is not allowed for this endpoint.'
                        ]
                    ]
                ]);
            }
    
            // Get Authorization header
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            //error_log(print_r($headers, true)); // Log all headers for debugging
            $authorization = $headers['Authorization'] 
                ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null) 
                ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);

            $apiKey = $authorization ? str_replace('Bearer ', '', $authorization) : '';
        
            if (empty($apiKey)) {
                sendErrorResponse(401, [
                    'errors' => [
                        [
                            'title' => 'Unauthorized',
                            'detail' => 'Authorization header is required.'
                        ]
                    ]
                ]);
            }
    
            if (!$this->userModels->isValidApiKey($apiKey)) {
                sendErrorResponse(403, [
                    'errors' => [
                        [
                            'title' => 'Forbidden',
                            'detail' => 'Invalid API key.'
                        ]
                    ]
                ]);
            }

            $input = [];

            //error_log(print_r($_POST, true)); // Log the POST data for debugging

            // Validate required fields
            $requiredFields = ['code'];
            foreach ($requiredFields as $field) {
                $input[$field] = $this->userModels->sanitizeInput($_POST[$field] ?? '');
                if (empty($input[$field])) {
                    sendErrorResponse(400, [
                        'errors' => [
                            [
                                'title' => 'Bad Request',
                                'detail' => "The Hotel '$field' is required."
                            ]
                        ]
                    ]);
                }
            }
    
          
            $code = $input['code'];
            $destination = isset($_POST['destination']) ? $_POST['destination'] : null;
            $checkIn = isset($_POST['checkIn']) ? $_POST['checkIn'] : null;
            $checkOut = isset($_POST['checkOut']) ? $_POST['checkOut'] : null;
            $occupancies = $this->hotelModel->buildOccupancy($_POST);
            $this->hotelModel->validateInputs($destination, $checkIn, $checkOut, $occupancies);
            $token = $this->hotelModel->getAuthToken($apiKey);
            $fetchCountryCode = $this->hotelModel->fetchHotelCountryCode($code);
            $payload = [
                'stay' => [
                    'checkIn' => date('Y-m-d', strtotime($checkIn)),
                    'checkOut' => date('Y-m-d', strtotime($checkOut))
                ],
                'occupancies' => $occupancies, // Now contains multiple room configurations
                "hotels" => [
                    "hotel" =>  [intval($code)]
                ],
                "sourceMarket" => $fetchCountryCode[0]['country_code'],
                "reviews" => [
                    [
                        "type" => "HOTELBEDS",
                        'minRate' => 1,
                        'maxRate' => 5,
                        "minReviewCount" => 1
                    ]
                ],
            ];

            $response = $this->hotelModel->makeApiRequest($payload, $token['key'], $token['hash'], true);
            sendSuccessResponse($response);
                
        }

        public function fetchRoomFacilities(){

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendErrorResponse(405, [
                    'errors' => [
                        [
                            'title' => 'Method Not Allowed',
                            'detail' => 'The requested method is not allowed for this endpoint.'
                        ]
                    ]
                ]);
            }
    
            // Get Authorization header
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            //error_log(print_r($headers, true)); // Log all headers for debugging
            $authorization = $headers['Authorization'] 
                ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null) 
                ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);

            $apiKey = $authorization ? str_replace('Bearer ', '', $authorization) : '';
        
            if (empty($apiKey)) {
                sendErrorResponse(401, [
                    'errors' => [
                        [
                            'title' => 'Unauthorized',
                            'detail' => 'Authorization header is required.'
                        ]
                    ]
                ]);
            }

            if (!$this->userModels->isValidApiKey($apiKey)) {
                sendErrorResponse(403, [
                    'errors' => [
                        [
                            'title' => 'Forbidden',
                            'detail' => 'Invalid API key.'
                        ]
                    ]
                ]);
            }

            
            $hotelCode = intval($this->userModels->sanitizeInput($_POST['hotelCode'] ?? ''));
            $roomCodes = $this->userModels->sanitizeInput($_POST['roomCodes'] ?? '');
            $roomFacilities = $this->hotelModel->getRoomFacilitiesByHotelAndRooms($hotelCode, $roomCodes);
            $roomImages = $this->hotelModel->getRoomImagesByHotelAndRooms($hotelCode, $roomCodes);
            $results = [
                'roomFacilities' => $roomFacilities,
                'roomImages' => $roomImages
            ];

            sendSuccessResponse($results);
        }

        public function fetchHotelFacilities(){

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendErrorResponse(405, [
                    'errors' => [
                        [
                            'title' => 'Method Not Allowed',
                            'detail' => 'The requested method is not allowed for this endpoint.'
                        ]
                    ]
                ]);
            }

            // Get Authorization header
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            //error_log(print_r($headers, true)); // Log all headers for debugging
            $authorization = $headers['Authorization'] 
                ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null) 
                ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);

            $apiKey = $authorization ? str_replace('Bearer ', '', $authorization) : '';
        
            if (empty($apiKey)) {
                sendErrorResponse(401, [
                    'errors' => [
                        [
                            'title' => 'Unauthorized',
                            'detail' => 'Authorization header is required.'
                        ]
                    ]
                ]);
            }

            if (!$this->userModels->isValidApiKey($apiKey)) {
                sendErrorResponse(403, [
                    'errors' => [
                        [
                            'title' => 'Forbidden',
                            'detail' => 'Invalid API key.'
                        ]
                    ]
                ]);
            }

            //error_log(print_r($_POST, true));
            $hotelCode = $this->userModels->sanitizeInput(intval($_POST['hotelCode'] ?? ''));
            $results = $this->hotelModel->getHotelFacilities($hotelCode);
            sendSuccessResponse($results);
        }

        public function fetchCheckRate(){

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                sendErrorResponse(405, [
                    'errors' => [
                        [
                            'title' => 'Method Not Allowed',
                            'detail' => 'The requested method is not allowed for this endpoint.'
                        ]
                    ]
                ]);
            }

            // Get Authorization header
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            //error_log(print_r($headers, true)); // Log all headers for debugging
            $authorization = $headers['Authorization'] 
                ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null) 
                ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);

            $apiKey = $authorization ? str_replace('Bearer ', '', $authorization) : '';
        
            if (empty($apiKey)) {
                sendErrorResponse(401, [
                    'errors' => [
                        [
                            'title' => 'Unauthorized',
                            'detail' => 'Authorization header is required.'
                        ]
                    ]
                ]);
            }

            if (!$this->userModels->isValidApiKey($apiKey)) {
                sendErrorResponse(403, [
                    'errors' => [
                        [
                            'title' => 'Forbidden',
                            'detail' => 'Invalid API key.'
                        ]
                    ]
                ]);
            }

            $rateKeys = $this->userModels->sanitizeInput($_POST['ratekeys'] ?? '');

            if (empty($rateKeys)) {
                sendErrorResponse(400, [
                    'errors' => [
                        [
                            'title' => 'Bad Request',
                            'detail' => 'Rate keys are required.'
                        ]
                    ]
                ]);
            }

            $rateKeysArray = explode(',', $rateKeys);

            // Create the payload rooms array using the exploded rate keys
            $payload['rooms'] = array_map(function($rateKey) {
                return [
                    "rateKey" => $rateKey
                ];
            }, $rateKeysArray);

            error_log(print_r($payload, true));

            // 3. Get authenticated token
            $token = $this->hotelModel->getAuthToken($apiKey);

            //error_log(print_r($_POST, true));
            $results = $this->hotelModel->getCheckRate($apiKey, $token['hash'], $payload);
            error_log(print_r($results, true));
            
            sendSuccessResponse($results);
        }

        public function addRoomToCart(){
            $response = $this->userModels->addToCart($_POST);
            sendSuccessResponse($response);
        }


        public function fetchCart(){
            $user = $this->userModels->checkUser($_POST['user_id'], $_POST['session_id']);
            $user_check_id = $user['userid'];
            $user_check_session = $user['session_id'];
            $response = $this->userModels->getCartItem($user_check_id, $user_check_session);
            sendSuccessResponse($response);
        }

        public function removeCartItem(){
            $cart_id = intval($this->userModels->sanitizeInput($_POST['cart_id'] ?? ''));
            error_log("Cart ID to remove: " . $cart_id);
            if (empty($cart_id)) {
                sendErrorResponse(400, [
                    'errors' => [
                        [
                            'title' => 'Bad Request',
                            'detail' => 'Cart ID is required.'
                        ]
                    ]
                ]);
            }
            $response = $this->userModels->removeFromCart($cart_id);
            sendSuccessResponse($response);
        }

        public function fetchCartById(){
            $cart_id = intval($this->userModels->sanitizeInput($_POST['cart_id'] ?? ''));
            if (empty($cart_id)) {
                sendErrorResponse(400, [
                    'errors' => [
                        [
                            'title' => 'Bad Request',
                            'detail' => 'Cart ID is required.'
                        ]
                    ]
                ]);
            }

            $response = $this->userModels->getCartItemById($cart_id);
            if($response === null){
                sendErrorResponse(404, [
                    'errors' => [
                        [
                            'title' => 'Not Found',
                            'detail' => 'Cart item not found.'
                        ]
                    ]
                ]);
            }
            $hotelInfo = $this->hotelModel->fetchHotelInfoByCode($response['room_data']['images'][0]['hotel_code'] ?? null);
            $response['hotel_info'] = $hotelInfo;
            sendSuccessResponse($response);
        }


        public function bookHotel(){
            // Get Authorization header
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            //error_log(print_r($headers, true)); // Log all headers for debugging
            $authorization = $headers['Authorization'] 
                ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null) 
                ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);

            $apiKey = $authorization ? str_replace('Bearer ', '', $authorization) : '';
        
            if (empty($apiKey)) {
                sendErrorResponse(401, [
                    'errors' => [
                        [
                            'title' => 'Unauthorized',
                            'detail' => 'Authorization header is required.'
                        ]
                    ]
                ]);
            }

            if (!$this->userModels->isValidApiKey($apiKey)){
                sendErrorResponse(403, [
                    'errors' => [
                        [
                            'title' => 'Forbidden',
                            'detail' => 'Invalid API key.'
                        ]
                    ]
                ]);
            }

             // 3. Get authenticated token
            $token = $this->hotelModel->getAuthToken($apiKey);

            $response = $this->hotelModel->bookHotel($apiKey, $token['hash'], $_POST);
            sendSuccessResponse($response);
        }

        public function stripePaymentIntent(){
            $amount = $this->userModels->sanitizeInput(intval($_POST['amount'] ?? ''));
            $currency = $this->userModels->sanitizeInput($_POST['currency'] ?? '');
            $response = $this->userModels->createStripePayment($amount, $currency);
            sendSuccessResponse($response);
        }

        public function confirmPayment(){
            $payIntentid = $this->userModels->sanitizeInput($_POST['payment_intent_id'] ?? '');
            $response = $this->userModels->confirmStripePayment($payIntentid);
            sendSuccessResponse($response);
        }

        public function HotelBookingDetails(){
            $bookingReference = $this->userModels->sanitizeInput($_POST['bookingId'] ?? '');
            $response = $this->hotelModel->fetchHotelBookingDetails($bookingReference);
            sendSuccessResponse($response);
        }
        

}