<?php
class FlightController {
    private $db;
    private $flightModel;

    public function __construct($db){
        $this->db = $db;
        $this->flightModel = new FlightModels($db);
    }

     public function getOneWayFlight() {
        // Ensure it's a POST request
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Content-Type: application/json');
            return [
                'errors' => [
                    [
                        'title' => 'Method Not Allowed',
                        'detail' => 'The requested method is not allowed for this endpoint.'
                    ]
                ]
            ];
        }
    
        // Get Authorization header
        $headers = getallheaders();
        $authorization = $headers['Authorization'] ?? null;
        $apiKey = !is_null($authorization) ? str_replace('Bearer ', '', $authorization) : '';
    
        if (empty($apiKey)) {
            http_response_code(401); // 401 Unauthorized
            return [
                'errors' => [
                    [
                        'title' => 'Unauthorized',
                        'detail' => 'Authorization header is required.'
                    ]
                ]
            ];
        }
    
        // Required POST fields
        $requiredFields = ['origin', 'destination', 'departureDate', 'adults'];
        foreach ($requiredFields as $field) {
            if (!isset($_POST[$field])) { // Use isset() instead of empty()
                http_response_code(400); // 400 Bad Request
                header('Content-Type: application/json');
                return [
                    'errors' => [
                        [
                            'title' => 'Missing Required Fields',
                            'detail' => "Field '$field' is required."
                        ]
                    ]
                ];
            }
        }
    
        // Extract POST data safely
        $origin = $_POST['origin'];
        $destination = $_POST['destination'];
        $departureDate = $_POST['departureDate'];
        $adults = (int) $_POST['adults'];
        $children = isset($_POST['children']) ? (int) $_POST['children'] : 0;
        $infants = isset($_POST['infants']) ? (int) $_POST['infants'] : 0;
        $travelClass = isset($_POST['travelClass']) ? strtoupper(trim($_POST['travelClass'])) : 'ECONOMY';
        $nonStop = isset($_POST['nonStop']) ? $_POST['nonStop'] : "false";
        $maxPrice = isset($_POST['maxPrice']) ? (float) $_POST['maxPrice'] : null;
        $includedAirlineCodes = isset($_POST['includedAirlineCodes']) ? $_POST['includedAirlineCodes'] : null;
        $currency = "USD";
    
        // Call the model function to fetch flight data
        $response = $this->flightModel->FetchOneWayFlight(
            $origin, $destination, $departureDate, $adults, $children,
            $infants, $travelClass, $nonStop, $maxPrice, $includedAirlineCodes, $apiKey, $currency
        );
    
        // Return the response as JSON
        //header('Content-Type: application/json');
        ///echo json_encode($response, JSON_PRETTY_PRINT);
        //exit;
        return $response;
    }
    

    public function getRoundTripFlight() {
        
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            // Check if the Authorization header is present and not empty
            $headers = getallheaders();
            $authorization = $headers['Authorization'] ?? null;
            $apiKey =  !is_null($authorization) ? str_replace('Bearer ', '', $authorization) : '';

            if (empty($apiKey)) {

                return [
                    'errors' => [
                        [
                            'title' => 'Method Not Allowed',
                            'detail' => "Authorization header is required"
                        ]
                    ]
                ];

            }
    
            // Required POST fields
            $requiredFields = ['origin', 'destination', 'departureDate', 'returnDate', 'adults'];
            foreach ($requiredFields as $field) {
                if (empty($_POST[$field])) {
                    return [
                        'errors' => [
                            [
                                'title' => 'Required fields',
                                'detail' => "Field '$field' is required"
                            ]
                        ]
                    ];
                }
            }
    
            // Extract POST data
            $origin = $_POST['origin'];
            $destination = $_POST['destination'];
            $departureDate = $_POST['departureDate'];
            $returnDate = $_POST['returnDate'];
            $adults = $_POST['adults'];
            $children = $_POST['children'] ?? 0;
            $infants = $_POST['infants'] ?? 0;
            $travelClass = isset($_POST['travelClass']) ? strtoupper($_POST['travelClass']) : strtoupper('Economy');
            $nonStop = $_POST['nonStop'] ?? "false";
            $maxPrice = $_POST['maxPrice'] ?? null;
            $includedAirlineCodes = $_POST['includedAirlineCodes'] ?? null;
            $currency = "USD";
    
            // Call the model function to fetch flight data
            $response = $this->flightModel->FetchRoundTripFlight(
                $origin, $destination, $departureDate, $returnDate, $adults, $children,
                $infants, $travelClass, $nonStop, $maxPrice, $includedAirlineCodes, $apiKey, $currency
            );
    
            // Return the response as JSON
            // Return the response as JSON
            //header('Content-Type: application/json');
            //echo json_encode($response, JSON_PRETTY_PRINT);
            //exit;

            return $response;

        } else {

            return [
                'errors' => [
                    [
                        'title' => 'Method Not Allowed',
                        'detail' => 'The requested method is not allowed for this endpoint.'
                    ]
                ]
            ];

        }
    }

    public function getFlightDetails(){

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            // Check if the Authorization header is present and not empty
            $headers = getallheaders();
            $authorization = $headers['Authorization'] ?? null;
            $apiKey =  !is_null($authorization) ? $apiKey = str_replace('Bearer ', '', $authorization) : '';
            
            if (empty($apiKey)) {
               return [
                    'errors' => [
                        [
                            'title' => 'Method Not Allowed',
                            'detail' => "Authorization header is required"
                        ]
                    ]
                ];
            }
    
            // Required POST fields
            $requiredFields = ['flight_offer'];
            foreach ($requiredFields as $field) {
                if (empty($_POST[$field])) {
                    return [
                        'errors' => [
                            [
                                'title' => 'Method Not Allowed',
                                'detail' => "Field '$field' is required"
                            ]
                        ]
                    ];
                }
            }
    
            // Extract POST data
            $flightdata = $_POST['flight_offer'];
    
            // Call the model function to fetch flight data
            return $this->flightModel->FlightBookingPrice($flightdata,$apiKey);


        } else {

            return [
                'errors' => [
                    [
                        'title' => 'Method Not Allowed',
                        'detail' => 'The requested method is not allowed for this endpoint.'
                    ]
                ]
            ];
        }
    }

    public function FlightBooking(){
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            // Check if the Authorization header is present and not empty
            $headers = getallheaders();
            $authorization = $headers['Authorization'] ?? null;
            $apiKey =  !is_null($authorization) ? $apiKey = str_replace('Bearer ', '', $authorization) : '';
            
            if (empty($apiKey)) {

               return [
                    'errors' => [
                        [
                            'title' => 'Method Not Allowed',
                            'detail' => 'Authorization header is required'
                        ]
                    ]
                ];
            }
            // Extract POST data
            $flightdata = $_POST['flight_offers'];
            $travelers = $_POST['travelers'];
    
            // Call the model function to fetch flight data
            $response = $this->flightModel->processFlightBooking($flightdata,$travelers,$apiKey);;
    
            // Return the response as JSON
            return $response;

        } else {

            return [
                'errors' => [
                    [
                        'title' => 'Method Not Allowed',
                        'detail' => 'The requested method is not allowed for this endpoint.'
                    ]
                ]
            ];

        }
    }

}