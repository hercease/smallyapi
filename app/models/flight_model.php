<?php
class FlightModels
{
    private $db;
    private $baseUrl;
    private $clientKey;
    private $clientSecret;
    public function __construct($db)
    {
        $this->db = $db;
        $this->baseUrl = BASE_URL;
        $this->clientSecret = CLIENT_SECRET;
        $this->clientKey = CLIENT_KEY;
    }

    public function makeCurlGetRequest($url, $params = [], $headers = [], $timeout = 30) {
        $curl = curl_init();
        
        // Append query parameters to URL if they exist
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
    
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,                     // Set the URL
            CURLOPT_RETURNTRANSFER => true,         // Return the response as a string
            CURLOPT_HTTPGET => true,                // Specify that this is a GET request
            CURLOPT_HTTPHEADER => $headers,         // Pass custom headers
            CURLOPT_TIMEOUT => $timeout,            // Set timeout in seconds
        ]);
    
        $response = curl_exec($curl);               // Execute the cURL request
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get HTTP response code
        $error = curl_error($curl);                // Check for errors
        curl_close($curl);                         // Close the cURL session
    
        return [
            'response' => $response,                // Response body
            'httpCode' => $httpCode,                // HTTP status code
            'error' => $error,                      // Any cURL errors
        ];
    }

    public function makeCurlPostRequest($url, $data = [], $headers = [], $timeout = 30) {
        $curl = curl_init();
    
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,                     // Set the URL
            CURLOPT_RETURNTRANSFER => true,         // Return the response as a string
            CURLOPT_POST => true,                   // Specify that this is a POST request
            CURLOPT_POSTFIELDS => http_build_query($data), // Encode the data for x-www-form-urlencoded
            CURLOPT_HTTPHEADER => $headers,         // Pass custom headers
            CURLOPT_TIMEOUT => $timeout,            // Set timeout in seconds
        ]);
    
        $response = curl_exec($curl);               // Execute the cURL request
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get HTTP response code
        $error = curl_error($curl);                // Check for errors
        curl_close($curl);                         // Close the cURL session
    
        return [
            'response' => $response,                // Response body
            'httpCode' => $httpCode,                // HTTP status code
            'error' => $error,                      // Any cURL errors
        ];
    }
    
    

    public function getAccessToken($apiKey) {
        // Step 1: Validate the API key and determine credentials
        $credentials = $this->getClientCredentials($apiKey);
    
        if (!$credentials) {
            //throw new Exception("Invalid API Key");
            return [
                'errors' => [
                    [
                        'title' => 'Error',
                        'detail' => "Invalid API Key"
                    ]
                ]
            ];
        }
    
        // Step 2: Check if a valid token exists in the database for these credentials
        $stmt = $this->db->prepare("SELECT token, expires_at FROM api_tokens WHERE api_key = ? ORDER BY id DESC LIMIT  1");
        $stmt->bind_param("s", $credentials['api_key']);
        $stmt->execute();
        $result = $stmt->get_result();
        $tokenData = $result->fetch_assoc();
    
        if ($tokenData) {

            $currentTime = date("Y-m-d H:i:s");
            $expiresAt = $tokenData['expires_at'];
    
            // If token is still valid, return it
            if (strtotime($currentTime) < strtotime($expiresAt)) {
                return $tokenData['token'];
            }

        }
    
        // Step 3: If no valid token or token expired, request a new one
        $tokenDetails = $this->fetchNewAccessToken($credentials);
    
       // Step 1: Check i f the table has an entry for the given API key
        $stmt = $this->db->prepare("SELECT COUNT(*) AS total FROM api_tokens WHERE api_key = ?");
        $stmt->bind_param("s", $credentials['api_key']);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $totalRows = (int) $row['total'];
        $stmt->close();

        if ($totalRows === 0) {
            // No entry found, insert the token
            $stmt = $this->db->prepare(
                "INSERT INTO api_tokens (api_key, token, expires_at) 
                VALUES (?, ?, ?)"
            );

            // Bind parameters and execute the query
            $stmt->bind_param("sss", $credentials['api_key'], $tokenDetails['access_token'], $tokenDetails['expires_at']);
            $stmt->execute();
            $stmt->close();

        } else {
            // Entry exists, update the token
            $stmt = $this->db->prepare(
                "UPDATE api_tokens 
                SET token = ?, expires_at = ? 
                WHERE api_key = ? 
                LIMIT 1"
            );

            // Bind parameters and execute the query
            $stmt->bind_param("sss", $tokenDetails['access_token'], $tokenDetails['expires_at'], $credentials['api_key']);
            $stmt->execute();
            $stmt->close();
        }
    
        return $tokenDetails['access_token'];
    }
    

    private function getClientCredentials($apiKey) {

        $stmt = $this->db->prepare("SELECT token FROM api_users WHERE token = ?");
        $stmt->bind_param("s", $apiKey);
        $stmt->execute();
        $result = $stmt->get_result();
        $userData = $result->fetch_assoc();

        if (!$userData) {

            return [
                'errors' => [
                    [
                        'title' => 'Error',
                        'detail' => 'API key not found'
                    ]
                ]
            ];

        }

        // Use default credentials
        return [
            'client_id' => $this->clientKey,
            'client_secret' => $this->clientSecret,
            'api_key' => $apiKey
        ];
    }

    public function fetchNewAccessToken($credentials) {
        $ch = curl_init($this->baseUrl . "/v1/security/oauth2/token");
     
        $data = [
            'grant_type' => 'client_credentials',
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret']
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query($data)
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            throw new Exception('Error fetching access token: ' . curl_error($ch));
        }

        curl_close($ch);

        $responseData = json_decode($response, true);
        if (!isset($responseData['access_token'])) {
            throw new Exception('Invalid response from Smallyfares API: ' . $response);
        }

        // Calculate expiration time (Amadeus provides "expires_in" in seconds)
        $expiresAt = (new DateTime())->add(new DateInterval('PT' . $responseData['expires_in'] . 'S'));

        return [
            'access_token' => $responseData['access_token'],
            'expires_at' => $expiresAt->format('Y-m-d H:i:s')
        ];
    }

    public function FetchOneWayFlight(
        $origin,
        $destination,
        $departureDate,
        $adults,
        $children,
        $infants,
        $travelClass,
        $nonStop,
        $maxPrice,
        $includedAirlineCodes,
        $apiKey,
        $currency
    ) {
        // Prepare the query string
        $queryParams = http_build_query([
            'originLocationCode' => $origin,
            'destinationLocationCode' => $destination,
            'departureDate' => $departureDate,
            'adults' => $adults,
            'children' => $children,
            'infants' => $infants,
            'travelClass' => $travelClass,
            'nonStop' => $nonStop,
            'maxPrice' => $maxPrice,
            'includedAirlineCodes' => $includedAirlineCodes,
            'currencyCode' => $currency
        ]);
    
        // Construct the full URL with query parameters
        $url = $this->baseUrl . '/v2/shopping/flight-offers?' . $queryParams;
    
        // Initialize cURL
        $ch = curl_init($url);
    
        // Get the access token
        $accessToken = $this->getAccessToken($apiKey);
    
        // Set headers
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ];
    
        // Configure cURL options
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers
        ]);
    
        // Execute the request
        $response = curl_exec($ch);
    
        // Handle errors
        if ($response === false) {
            throw new Exception('Error fetching flight offers: ' . curl_error($ch));
        }
    
        // Close the cURL session
        curl_close($ch);
    
        // Return the decoded JSON response
        $resp = json_decode($response, true);

        if(isset($resp['errors'])){

            return $resp;

        } else {

            $prices = array_map(fn($item) => $item['price']['grandTotal'], $resp['data']);
            $res['data'] = $resp['data'];
            $res['minprice'] = !empty($prices) ? min($prices) : 0;
            $res['maxprice'] = !empty($prices) ? max($prices) : 0;
            $res['airlines'] = $resp['dictionaries']['carriers'] ?? [];
            $res['count'] = $resp['meta']['count'] ?? 0;

            return $res;
        }

        
    }

    public function FetchRoundTripFlight(
        $origin,
        $destination,
        $departureDate,
        $returnDate,
        $adults,
        $children,
        $infants,
        $travelClass,
        $nonStop,
        $maxPrice,
        $includedAirlineCodes,
        $apiKey,
        $currency
    ) {
        // Prepare the query string
        $queryParams = http_build_query([
            'originLocationCode' => $origin,
            'destinationLocationCode' => $destination,
            'departureDate' => $departureDate,
            'returnDate' => $returnDate,
            'adults' => $adults,
            'children' => $children,
            'infants' => $infants,
            'travelClass' => $travelClass,
            'nonStop' => $nonStop,
            'maxPrice' => $maxPrice,
            'includedAirlineCodes' => $includedAirlineCodes,
            'currencyCode' => $currency
        ]);

        // Construct the full URL with query parameters
        $url = $this->baseUrl . '/v2/shopping/flight-offers?' . $queryParams;

        // Initialize cURL
        $ch = curl_init($url);

        // Get the access token
        $accessToken = $this->getAccessToken($apiKey);

        // Set headers
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ];

        // Configure cURL options
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers
        ]);

        // Execute the request
        $response = curl_exec($ch);

        // Handle errors
        if ($response === false) {
            throw new Exception('Error fetching flight offers: ' . curl_error($ch));
        }

        // Close the cURL session
        curl_close($ch);

        // Return the decoded JSON response
        $resp = json_decode($response, true);
        $errorMessage = "";
        $res = [];
        if(isset($resp['errors'])){
            // Store the message inside the error object
            return $resp;

        } else {
            
            $prices = array_map(fn($item) => $item['price']['grandTotal'], $resp['data']);
            $res['data'] = $resp['data'];
            $res['minprice'] = min($prices);
            $res['maxprice'] = max($prices);
            $res['airlines'] = $resp['dictionaries']['carriers'];
            $res['count'] = $resp['meta']['count'];

            return $res;

        }
    }


    public function FlightBookingPrice($flightdata,$apiKey){

        $url = $this->baseUrl . '/v1/shopping/flight-offers/pricing';
        $flightOffer = [
            "data" => [
                "type" => "flight-offers-pricing",
                "flightOffers" => [$flightdata] // Select the first flight offer
            ]
        ];

        $accessToken = $this->getAccessToken($apiKey);
    
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json'
        ];


        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($flightOffer));

        $response = curl_exec($ch);
        curl_close($ch);
    
        return json_decode($response, true);

    }

    public function processFlightBooking($flightdata,$travelers,$apiKey){
        $url = $this->baseUrl . '/v1/booking/flight-orders';

        $flightOffer = [
            "data" => [
                "type" => "flight-order",
                "flightOffers" => [$flightdata],
                "travelers" => $travelers // Select the first flight offer
            ]
        ];

        $accessToken = $this->getAccessToken($apiKey);
    
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json'
        ];


        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($flightOffer));

        $response = curl_exec($ch);
        curl_close($ch);
    
        $resp = json_decode($response, true);
        $errorMessage = "";
        $res = [];
        
        if(isset($resp['errors'])){
            
            /*foreach ($resp['errors'] as $error) {
                $errorMessage .= "Error " . $error['code'] . ": " . $error['title'] . "\n";
                
                // Check if 'source' exists and is not empty
                if (!empty($error['source'])) {
                    if (isset($error['source']['pointer'])) {
                        $errorMessage .= "Field: " . $error['source']['pointer'] . "\n";
                    }
                    if (isset($error['source']['example'])) {
                        $errorMessage .= "Expected Value: " . var_export($error['source']['example'], true) . "\n";
                    }
                }

                $errorMessage .= "Issue: " . $error['detail'] . "\n";
                $errorMessage .= "----------------------\n"; // Separator for multiple errors
            }*/

            // Store the message inside the error object
            return $resp;

        } else {
            
            return $resp;
        }
    }
    


}


?>