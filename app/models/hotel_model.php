<?php
require_once 'vendor/autoload.php';
use Phpfastcache\Helper\Psr16Adapter;
require_once('app/models/stripe/init.php');
class Hotelmodels {
    private $db;
    private $baseUrl;
    private $Key;
    private $Secret;
    private $userModel;
    private $cache;
    private $emailTemplate;
    public function __construct($db)
    {
        $this->db = $db;
        $this->Key = HOTEL_KEY;
        $this->Secret = HOTEL_SECRET;
        $this->cache =  new Psr16Adapter('Files');
        $this->emailTemplate = 'app/models/email_template.html';
        $this->userModel = new UserModel($db);
    }

    public function getClientCredentials($apiKey) {

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
            'hotel_key' => $this->Key,
            'hotel_secret' => $this->Secret,
            'api_key' => $apiKey
        ];
    }

    public function getAuthToken($apiKey) {

        $credentials = $this->getClientCredentials($apiKey);
        $hotelKey = $credentials['hotel_key'];
        $hotelSecret = $credentials['hotel_secret'];
        $currentTimestampInSeconds = time();
        $stringToHash = $hotelKey . '' . $hotelSecret . '' . $currentTimestampInSeconds;
        $hash = hash('sha256', $stringToHash);

        return [
            'hash' => $hash,
            'secret' => $hotelSecret,
            'key' => $hotelKey
        ];
        
    }

    public function search(
        string $apiKey,
        string $destination,
        string $checkIn,
        string $checkOut,
        array $occupancies, // Changed from individual parameters
        ?float $minRate = null,
        ?float $maxRate = null,
        ?int $minCategory = null,
        ?int $maxCategory = null,
        ?int $maxRooms = null,
        ?float $minRating = null,
        ?float $maxRating = null,
        ?array $accommodations = null,
        int $rooms = 1,
        int $page = 1,
        int $pageSize = 6
    ): array {
        
        // Validate inputs
        $this->validateInputs($destination, $checkIn, $checkOut, $occupancies);

        // Build request payload
        $payload = $this->buildRequestPayload(
            $destination,
            $checkIn,
            $checkOut,
            $occupancies,
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

        //error_log("Final Payload: " . print_r($payload, true));

        // 3. Get authenticated token
        $token = $this->getAuthToken($apiKey);

        // 4. Make API request
        return $this->makeApiRequest($payload, $token['key'], $token['hash'], false);
    }

    /**
     * Validate all input parameters
     */
    public function validateInputs( 
        string $destination,
        string $checkIn,
        string $checkOut,
        array $occupancies
    ): void {

        try {
            // Validate dates
            if (!strtotime($checkIn) || !strtotime($checkOut)) {
                throw new InvalidArgumentException("Invalid date format. Use YYYY-MM-DD");
            }

            $checkInDate = strtotime($checkIn);
            $checkOutDate = strtotime($checkOut);
            $today = strtotime('today');

            if ($checkInDate === false || $checkOutDate === false) {
                throw new InvalidArgumentException("Invalid date format. Use YYYY-MM-DD");
            }

            if ($checkInDate < $today) {
                throw new InvalidArgumentException("Check-in date cannot be in the past");
            }

            if ($checkOutDate <= $checkInDate) {
                throw new InvalidArgumentException("Check-out date must be after check-in");
            }

            if (empty($destination)) {
                throw new InvalidArgumentException("Destination code cannot be empty");
            }

            // Validate each room configuration
            foreach ($occupancies as $index => $room) {
                $roomNumber = $index + 1;
                
                // Validate that required keys exist
                if (!isset($room['adults'])) {
                    throw new InvalidArgumentException("Room {$roomNumber}: Adults count is required");
                }
                
                if (!isset($room['children'])) {
                    throw new InvalidArgumentException("Room {$roomNumber}: Children count is required");
                }

                if (!isset($room['rooms'])) {
                    throw new InvalidArgumentException("Room {$roomNumber}: Rooms count is required");
                }

                // Get values
                $adults = (int)$room['adults'];
                $children = (int)$room['children'];
                $roomCount = (int)$room['rooms'];
                $paxes = $room['paxes'] ?? [];
                
                // Validate room count (should always be 1 per occupancy in this structure)
                if ($roomCount !== 1) {
                    throw new InvalidArgumentException("Room {$roomNumber}: Each occupancy must have exactly 1 room");
                }

                // Validate adults
                if ($adults < 1) {
                    throw new InvalidArgumentException("Room {$roomNumber}: At least one adult is required");
                }
                
                if ($adults > 3) {
                    throw new InvalidArgumentException("Room {$roomNumber}: Maximum 3 adults per room");
                }

                // Validate children
                if ($children < 0) {
                    throw new InvalidArgumentException("Room {$roomNumber}: Children count cannot be negative");
                }
                
                if ($children > 3) {
                    throw new InvalidArgumentException("Room {$roomNumber}: Maximum 3 children per room");
                }

                // Validate paxes structure if children exist
                if ($children > 0) {
                    if (!is_array($paxes) || empty($paxes)) {
                        throw new InvalidArgumentException(
                            "Room {$roomNumber}: Child paxes array required when children > 0"
                        );
                    }

                    // Count CH type paxes (children)
                    $childPaxes = array_filter($paxes, function($pax) {
                        return isset($pax['type']) && $pax['type'] === 'CH';
                    });

                    if (count($childPaxes) !== $children) {
                        throw new InvalidArgumentException(
                            sprintf("Room {$roomNumber}: Expected %d child paxes but got %d", 
                                $children, 
                                count($childPaxes)
                            )
                        );
                    }

                    // Validate each child pax
                    foreach ($childPaxes as $paxIndex => $pax) {
                        if (!isset($pax['age'])) {
                            throw new InvalidArgumentException(
                                "Room {$roomNumber}: Child pax at index {$paxIndex} is missing age"
                            );
                        }

                        $age = (int)$pax['age'];
                        if ($age < 1 || $age > 17) {
                            throw new InvalidArgumentException(
                                "Room {$roomNumber}: Child ages must be between 1-17 years. Invalid age: {$age}"
                            );
                        }

                        if (!isset($pax['type']) || $pax['type'] !== 'CH') {
                            throw new InvalidArgumentException(
                                "Room {$roomNumber}: Child pax at index {$paxIndex} must have type 'CH'"
                            );
                        }
                    }
                }

                // Validate total occupants per room (adults + children)
                // Note: Infants are typically included in children count or as separate paxes with age 0
                $totalOccupants = $adults + $children;
                if ($totalOccupants > 6) {
                    throw new InvalidArgumentException("Room {$roomNumber}: Maximum 6 occupants per room");
                }

                // Additional validation: Ensure room has at least 1 occupant
                if ($totalOccupants < 1) {
                    throw new InvalidArgumentException("Room {$roomNumber}: At least one occupant is required");
                }

                // Validate total paxes count matches adults + children
                $totalPaxes = count($paxes);
                if ($totalPaxes !== ($children)) {
                    throw new InvalidArgumentException(
                        sprintf("Room {$roomNumber}: Paxes count (%d) does not match adults + children count (%d)", 
                            $totalPaxes, 
                            $adults + $children
                        )
                    );
                }
            }

        } catch (InvalidArgumentException $e) {
            error_log("Input validation error: " . $e->getMessage());
            sendErrorResponse(400, [
                'success' => false,
                'errors' => [
                    [
                        'title' => 'Validation Error',
                        'detail' => $e->getMessage()
                    ]
                ],
                'data' => [],
                'pagination' => [
                    'current_page' => 1,
                    'page_size' => 6,
                    'total_items' => 0,
                    'total_pages' => 0,
                    'has_next' => false,
                    'has_prev' => false
                ]
            ]);
            exit;
        }
    }


    private function buildRequestPayload(
        string $destination,
        string $checkIn,
        string $checkOut,
        array $occupancies, // Changed from individual parameters to array of room configs
        ?float $minRate,
        ?float $maxRate,
        ?int $minCategory,
        ?int $maxCategory,
        ?int $maxRooms,
        ?float $minRating,
        ?float $maxRating,
        ?array $accommodations,
        int $rooms,
        int $page = 1,
        int $pageSize = 6
    ): array {

        $hotelIds = $this->getHotelIds($destination);

        $payload = [
            'stay' => [
                'checkIn' => date('Y-m-d', strtotime($checkIn)),
                'checkOut' => date('Y-m-d', strtotime($checkOut))
            ],
            'occupancies' => $occupancies, // Now contains multiple room configurations
            "hotels" => [
                "hotel" =>  $hotelIds['hotelId'] 
            ],
            "sourceMarket" => $hotelIds['country_code'],
            "reviews" => [
                [
                    "type" => "HOTELBEDS",
                    'minRate' => $minRating ?? 1,
                    'maxRate' => $maxRating ?? 5,
                    "minReviewCount" => 1
                ]
            ],
            "pagination" => [
                "page" => $page,
                "pageSize" => $pageSize
            ]
        ];

        // Add filters if provided
        $filters = array_filter(
            [
                'minRate' => $minRate,
                'maxRate' => $maxRate,
                'minCategory' => $minCategory,
                'maxCategory' => $maxCategory,
                'maxRooms' => $maxRooms
            ],
            fn($value) => !is_null($value)
        );

        if (!empty($filters)) {
            $payload['filter'] = $filters;
        }

        if (!empty($accommodations)) {
            $payload['accommodations'] = $accommodations;
        }

        return $payload;
    }


    /**
     * Build occupancy structure
     */
    public function buildOccupancy($params)
    {
        $occupancies = [];
        
        // Build occupancies array from room data
        for ($i = 1; $i <= $params['rooms']; $i++) {
            $roomAdults = isset($params["room{$i}_adults"]) ? (int)$params["room{$i}_adults"] : 1;
            $roomChildren = isset($params["room{$i}_children"]) ? (int)$params["room{$i}_children"] : 0;
            $roomChildAges = isset($params["room{$i}_child_ages"]) ? $params["room{$i}_child_ages"] : '';
            
            // Create the base occupancy structure
            $occupancy = [
                'rooms' => 1,
                'adults' => $roomAdults,
                'children' => $roomChildren
            ];

            // Add child ages if children exist
            if ($roomChildren > 0 && !empty($roomChildAges)) {
                $ages = array_map('intval', explode(',', $roomChildAges));
                $occupancy['paxes'] = array_map(
                    fn($age) => ['type' => 'CH', 'age' => $age],
                    $ages
                );
            }
            
            // Add this room's occupancy to the array
            $occupancies[] = $occupancy;
        }

        return $occupancies;
    }

    public function makeApiRequest(array $payload, string $api_key, $signature, $status=false): array
    {
        $certPath = $_SERVER['DOCUMENT_ROOT'] . CRTFILE;
        $keyPath = $_SERVER['DOCUMENT_ROOT'] . KEYFILE;
        $caPath = $_SERVER['DOCUMENT_ROOT'] . CAFILE;

        $page = $payload['pagination']['page'] ?? 1;
        $pageSize = $payload['pagination']['pageSize'] ?? 6;

        //error_log("=== API REQUEST DEBUG ===");
        //error_log("Payload: " . print_r($payload, true));

        // Validate paths
        foreach ([$certPath, $keyPath, $caPath] as $file) {
            if (!file_exists($file)) {
                throw new RuntimeException("File not found: $file");
            }
        }

        $jsonPayload = json_encode($payload);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON: " . json_last_error_msg());
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api-mtls.hotelbeds.com/hotel-api/1.0/hotels",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSLCERT => $certPath,
            CURLOPT_SSLKEY => $keyPath,
            CURLOPT_CAINFO => $caPath,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "Api-key: $api_key",
                "X-Signature: $signature",
                "Accept: application/json",
                "Content-Type: application/json",
                "Accept-Encoding: gzip"
            ],
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_VERBOSE => true,
            CURLOPT_ENCODING => 'gzip'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("cURL error: " . $error);
        }

        $data = json_decode($response, true) ?? [];

        //error_log("API Response: " . print_r($data, true)); // Log the response for debugging
        
        if ($httpCode !== 200) {
            $errorMsg = $data['error'] ?? $response ?? 'Unknown error';
            //throw new RuntimeException("API error ($httpCode): " . $errorMsg);
            sendErrorResponse($httpCode, [
                'success' => false,
                'errors' => [
                    [
                        'title' => 'Internal Server Error',
                        'detail' => $errorMsg
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


         // Extract all hotels from API response
        $allHotels = $data['hotels']['hotels'] ?? [];

         // Apply pagination to the full dataset
        $paginatedResults = $this->paginateResults($allHotels, $page, $pageSize);

         // Extract object codes from the paginated
        $allObjectCodes = $this->extractObjectCodes($paginatedResults);

         // Fetch additional information from local database for ALL hotels
        $localData = $this->fetchLocalData($allObjectCodes, $status);

        // Enrich only the paginated results with local data
        $enrichedPaginatedData = $this->enrichResponseWithLocalData($paginatedResults, $localData);
        
        // Build final response with pagination info
        $finalResponse = $this->buildFinalResponse($enrichedPaginatedData, $allHotels, $page, $pageSize);

        return $finalResponse;
    
    }

    /**
     * Build final response with pagination info
     */
    private function buildFinalResponse(array $paginatedHotels, array $allHotels, int $page, int $pageSize): array
    {
        $totalItems = count($allHotels);
        $totalPages = ceil($totalItems / $pageSize);
        $accommodations = $this->fetchAccommodations();

        $allprices = [
            'overallMinRate' => PHP_INT_MAX, // Initialize with max possible value
            'overallMaxRate' => PHP_INT_MIN, // Initialize with min possible value
        ];

        foreach ($allHotels as $hotel) {
            // Update min/max rates
            $allprices['overallMinRate'] = min($allprices['overallMinRate'], round($hotel['minRate'], 0)); 
            $allprices['overallMaxRate'] = max($allprices['overallMaxRate'], round($hotel['maxRate'], 0));
        }

        //error_log("API Response: " . print_r($paginatedHotels, true));
        
        return [
            'success' => true,
            'data' => array_values($paginatedHotels),
            'prices' => $allprices,
            'accommodations' => $accommodations,
            'pagination' => [
                'current_page' => $page,
                'page_size' => $pageSize,
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1,
                'offset' => ($page - 1) * $pageSize
            ]
        ];
    }

    /**
     * Enrich paginated hotels with local database data
     */
    private function enrichResponseWithLocalData(array $paginatedHotels, array $localData): array
    {
        //error_log("=== ENRICHMENT DEBUG ===");
        //error_log("Paginated hotels count: " . count($paginatedHotels));
        //error_log("Local data count: " . count($localData));
        
        // Log all hotel codes from paginated results
        //$paginatedCodes = array_column($paginatedHotels, 'code');
        //error_log("Paginated hotel codes: " . implode(', ', $paginatedCodes));
        
        // Log all hotel codes from local data
        //$localCodes = array_column($localData, 'code');
        //error_log("Local data hotel codes: " . implode(', ', $localCodes));

        // Create lookup array
        $localDataByCode = array_column($localData, null, 'code');
        
        // Enrich each hotel
        foreach ($paginatedHotels as &$hotel) {
            $hotelCode = $hotel['code'] ?? 'unknown';
            
            if (isset($localDataByCode[$hotelCode])) {
                $hotel['local_data'] = $localDataByCode[$hotelCode];
                //error_log("✅ Enriched hotel $hotelCode with local data");
            } else {
                $hotel['local_data'] = [];
                //error_log("❌ No local data found for hotel $hotelCode");
            }
        }
        unset($hotel);
        
        //error_log("=== END DEBUG ===");
        
        return $paginatedHotels;
    }

    /**
     * Apply pagination to the full results array
     */
    private function paginateResults(array $allHotels, int $page, int $pageSize): array
    {
        $totalItems = count($allHotels);
        
        if ($totalItems === 0) {
            return [];
        }
        
        // Calculate pagination offsets
        $offset = ($page - 1) * $pageSize;
        
        // Ensure offset doesn't exceed array bounds
        if ($offset >= $totalItems) {
            return [];
        }
        
        // Extract the slice for the current page
        $paginatedHotels = array_slice($allHotels, $offset, $pageSize);
        
        return $paginatedHotels;
    }

    /**
     * Extract object codes from hotels array
    */
    private function extractObjectCodes(array $hotels): array
    {
        $codes = [];
        $roomCode = [];
        
        foreach ($hotels as $hotel) {
            if (isset($hotel['code'])) {
                $codes[] = $hotel['code'];
            }

            if (isset($hotel['rooms'])) {
                foreach($hotel['rooms'] as $room) {
                    $roomCode[] = $room['code'];
                }
            }
        }
        
        return ['codes' => $codes, 'roomCodes' => $roomCode];
    }


    public function getLastHotelCode() {
        // Start transaction for data consistency
        //$this->db->begin_transaction();
        
        try {
            // 1. Get the last code from database
            $stmt = $this->db->prepare("SELECT id FROM hotels ORDER BY id DESC LIMIT 1");
            $stmt->execute();
            $result = $stmt->get_result();
            
            // 2. If no records exist, start with prefix + 0001
            if ($result->num_rows === 0) {
                return 1;
            }
            
            $lastCode = $result->fetch_assoc()['id'];
            
            $lastCode = intval($lastCode);
            return $lastCode;
            
        } catch (Exception $e) {
            //$this->db->rollback();
            throw new Exception("Failed to generate next hotel code: " . $e->getMessage());
        }
    }

    private function getHotelIds(string $destination): array 
    {
        // Initialize with default values
        $result = [
            'hotelId' => [],
            'country_code' => null
        ];

        try {
            $stmt = $this->db->prepare("SELECT code, country_code FROM hotels WHERE dest_code = ? LIMIT 1000");
            if (!$stmt) {
                throw new RuntimeException("Database prepare failed: " . $this->db->error);
            }

            $stmt->bind_param("s", $destination);
            if (!$stmt->execute()) {
                throw new RuntimeException("Execute failed: " . $stmt->error);
            }

            $queryResult = $stmt->get_result();
            if (!$queryResult) {
                throw new RuntimeException("Get result failed: " . $stmt->error);
            }

            $countryCodes = [];
            while ($row = $queryResult->fetch_assoc()) {
                if (is_numeric($row['code'])) {
                    $result['hotelId'][] = (int)$row['code'];
                }
                if (!empty($row['country_code'])) {
                    $countryCodes[] = $row['country_code'];
                }
            }

            // Get most common country code if multiple exist
            if (!empty($countryCodes)) {
                $values = array_count_values($countryCodes);
                arsort($values);
                $result['country_code'] = (string)array_key_first($values);
            }

        } catch (Exception $e) {
            // Log error if needed
            error_log("getHotelIds error: " . $e->getMessage());
            return $result; // Return empty result
        } finally {
            if (isset($stmt)) {
                $stmt->close();
            }
        }

        return $result;
    }

    public function fetchHotels($apiKey, $from = 1, $to = 5) {
        $token = $this->getAuthToken($apiKey);
        $signature = $token['hash'];
        $api = $token['key'];
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.hotelbeds.com/hotel-content-api/1.0/hotels?fields=all&language=ENG&useSecondaryLanguage=false&from=$from&to=$to",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => [
                "Api-key: $api",
                "X-Signature: $signature",
                "Accept: application/json",
                "Accept-Encoding: gzip"
            ]
        ]);
        
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            die("Hotels fetch failed: " . curl_error($ch));
        }
        curl_close($ch);
        //return $signature;
        return json_decode($response, true);
    }

    public function getHotelDetails($apiKey, $hotelId) {
        $token = $this->getAuthToken($apiKey);
        $signature = $token['hash'];
        $api = $token['key'];
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.hotelbeds.com/hotel-content-api/1.0/hotels/$hotelId/details?language=ENG&useSecondaryLanguage=false",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => [
                "Api-key: $api",
                "X-Signature: $signature",
                "Accept: application/json",
                "Accept-Encoding: gzip"
            ]
        ]);
        
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            die("Hotel details fetch failed: " . curl_error($ch));
        }
        curl_close($ch);
        return json_decode($response, true);
    }

    public function getImageTypes($apiKey) {
        $token = $this->getAuthToken($apiKey);
        $signature = $token['hash'];
        $api = $token['key'];
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.hotelbeds.com/hotel-content-api/1.0/types/imagetypes?fields=all&language=ENG&from=1&to=100&useSecondaryLanguage=True",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => [
                "Api-key: $api",
                "X-Signature: $signature",
                "Accept: application/json",
                "Accept-Encoding: gzip"
            ]
        ]);
        
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            die("Hotel details fetch failed: " . curl_error($ch));
        }
        curl_close($ch);
        return json_decode($response, true);
    }

    public function searchHotelsOrDestinations($search, $requestType) {
        $data = [];
        $searchPattern = '%' . $search . '%';
    
        if ($requestType === 'search_destination') {
            $sql = "
                SELECT dest_name, dest_code, country_name, COUNT(*) AS dest_count 
                FROM hotels 
                WHERE dest_name LIKE ? OR dest_code LIKE ?
                GROUP BY dest_name 
            ";
        } elseif ($requestType === 'search_hotel') {
            $sql = "
                SELECT name, dest_name, dest_code, country_name 
                FROM hotels 
                WHERE name LIKE ? OR dest_code LIKE ?
                LIMIT 10
            ";
        } else {
            return ['result' => "Invalid request type"];
        }
    
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return ['result' => "Failed to prepare statement"];
        }
    
        $stmt->bind_param("ss", $searchPattern, $searchPattern);
        $stmt->execute();
        $result = $stmt->get_result();
    
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                if ($requestType === 'search_destination') {
                    $data[] = [
                        "dest" => $row['dest_name'],
                        "dest_code" => $row['dest_code'],
                        "country" => $row['country_name'],
                        "total" => $row['dest_count']
                    ];
                } else {
                    $data[] = [
                        "name" => $row['name'],
                        "dest" => $row['dest_name'],
                        "dest_code" => $row['dest_code'],
                        "country" => $row['country_name']
                    ];
                }
            }
            //error_log(print_r($data, true)); // Log the data for debugging
            return ['result' => $data];
        } else {
            return ['result' => []];
        }
    }

    public function fetchAccommodations()
    {
        $stmt = $this->db->prepare("SELECT code, description FROM accommodations");
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC); // Fetch ALL rows
    }

    public function fetchHotelCountryCode($code){
        $stmt = $this->db->prepare("SELECT country_code FROM hotels WHERE code = ?");
        $stmt->bind_param("i", $code);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Fetch additional data from local database using MySQLi with caching
     */
    private function fetchLocalData(array $objectCodes, bool $fetchDetailedData = false)
    {
        try {
            if (empty($objectCodes['codes'])) {
                throw new RuntimeException("No hotels match your filter, try adjusting your search criteria.");
            }


            $cacheKey = "hotels_" . ($fetchDetailedData ? "detailed_" : "summary_") . implode("_", $objectCodes['codes']);
            
            // Try to get from cache
            $cachedData = $this->cache->get($cacheKey);
            if ($cachedData !== null) {
                return $cachedData;
            }

            // Fetch base hotel data
            $hotels = $this->fetchBaseHotelData($objectCodes['codes']);
            
            if (empty($hotels)) {
                throw new RuntimeException("No hotels found for the given codes.");
            }

            // Choose data fetching strategy based on detail level
            if ($fetchDetailedData) {
                // Detailed view: all images and facilities
                $allImages = $this->fetchAllImages($objectCodes['codes'], ["GEN", "DEP", "COM", "PIS", "RES", "BAR", "PLA"]);
                $allFacilities = $this->fetchAllFacilities($objectCodes['codes']);
            } else {
                // Summary view: main image and limited facilities
                $allImages = $this->fetchMainImages($objectCodes['codes']);
                $allFacilities = $this->fetchLimitedFacilities($objectCodes['codes'], 70, 0, 4);
            }
            
            // Map data to hotels
            foreach ($hotels as &$hotel) {
                $code = $hotel['code'];
                $hotel['images'] = $allImages[$code] ?? [];
                $hotel['facilities'] = $allFacilities[$code] ?? [];
            }

            // Store result in cache for 1 hour
            $this->cache->set($cacheKey, $hotels, 3600);
            
            return $hotels;

        } catch (Exception $e) {
            throw new RuntimeException($e->getMessage());
        }
    }

    private function fetchBaseHotelData(array $objectCodes): array
    {
        if (empty($objectCodes)) return [];

        $cacheKey = "hotels_base_data_" . implode("_", $objectCodes);
            
        // Try to get from cache (inject cache dependency instead of creating here)
        $cachedData = $this->cache->get($cacheKey);
        if ($cachedData !== null) {
            return $cachedData;
        }

        $placeholders = implode(',', array_fill(0, count($objectCodes), '?'));
        $types = str_repeat('i', count($objectCodes));
        
        $stmt = $this->db->prepare("
            SELECT code, phone, address, postal_code, city, description 
            FROM hotels 
            WHERE code IN ($placeholders)
        ");

        $stmt->bind_param($types, ...$objectCodes);
        $stmt->execute();

        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Store in cache for 1 hour (3600 seconds)
        $this->cache->set($cacheKey, $result, 3600);
        
        return $result;
    }


    private function fetchAllImages(array $hotelCodes, array $imageTypes = []): array
    {
        if (empty($hotelCodes)) return [];

        $cacheKey = "hotels_allimages_" . implode("_", $hotelCodes);

        // Try to get from cache first
        $cachedData = $this->cache->get($cacheKey);
        if ($cachedData !== null) {
            return $cachedData;
        }
        
        $placeholders = implode(',', array_fill(0, count($hotelCodes), '?'));
        $typePlaceholders = implode(',', array_fill(0, count($imageTypes), '?'));
        
        $stmt = $this->db->prepare("
            SELECT hotel_code, path, description
            FROM hotel_images 
            WHERE hotel_code IN ($placeholders) 
            AND code IN ($typePlaceholders)
        ");
        
        $types = str_repeat('i', count($hotelCodes)) . str_repeat('s', count($imageTypes));
        $params = array_merge($hotelCodes, $imageTypes);
        
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $result = $this->groupByHotelCode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));

        // Store in cache for 1 hour (3600 seconds)
        $this->cache->set($cacheKey, $result, 3600);
        
        return $result;
        
    }

    private function fetchAllFacilities(array $hotelCodes): array
    {
        try{
            //error_log("Fetching all facilities for hotels: " . implode(", ", $hotelCodes));
            if (empty($hotelCodes)) return [];

            $cacheKey = "hotels_facilities_" . implode("_", $hotelCodes);

            // Try to get from cache first
            $cachedData = $this->cache->get($cacheKey);
            if ($cachedData !== null) {
                return $cachedData;
            }
            
            $placeholders = implode(',', array_fill(0, count($hotelCodes), '?'));
            
            $stmt = $this->db->prepare("
                SELECT hotel_code, description, group_code, indfee, indlogic, indyesorno, number, distance 
                FROM hotel_facilities 
                WHERE hotel_code IN ($placeholders) AND group_code IN (40, 10)
            ");
            
            $types = str_repeat('i', count($hotelCodes));
            $stmt->bind_param($types, ...$hotelCodes);
            $stmt->execute();
            
            $result = $this->groupByHotelCode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));

            // Store in cache for 1 hour (3600 seconds)
            $this->cache->set($cacheKey, $result, 3600);
            
            return $result;

        } catch (Exception $e) {
            throw new RuntimeException($e->getMessage());
        }
    }

    private function fetchRoomFacilities(array $hotelCodes, array $roomCodes): array
    {
        if (empty($hotelCodes) || empty($roomCodes)) {
            return [];
        }

        $hotelKey = implode('_', $hotelCodes);
        $typeKey = implode('_', $roomCodes);

        $cacheKey = "hotel_images_{$hotelKey}_types_{$typeKey}";

        // Try to get from cache first
        $cachedData = $this->cache->get($cacheKey);
        if ($cachedData !== null) {
            return $cachedData;
        }
        
        $hotelPlaceholders = implode(',', array_fill(0, count($hotelCodes), '?'));
        $roomPlaceholders = implode(',', array_fill(0, count($roomCodes), '?'));
        
        $stmt = $this->db->prepare("
            SELECT hotel_code, room_code, description, groupcode, indfee, indlogic, indyesorno, number 
            FROM hotel_room_facility
            WHERE hotel_code IN ($hotelPlaceholders) 
            AND room_code IN ($roomPlaceholders)
        ");
        
        // Bind both hotel codes and room codes
        $types = str_repeat('i', count($hotelCodes)) . str_repeat('i', count($roomCodes));
        $params = array_merge($hotelCodes, $roomCodes);
        
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Store in cache for 1 hour (3600 seconds)
        $this->cache->set($cacheKey, $result, 3600);
        
        return $result;
    }

    private function fetchMainImages(array $hotelCodes): array
    {
        if (empty($hotelCodes)) return [];

        // Generate cache key
        sort($hotelCodes);
        $cacheKey = "main_images_" . md5(implode("_", $hotelCodes));

        // Try cache first
        $cachedData = $this->cache->get($cacheKey);
        if ($cachedData !== null) {
            return $cachedData;
        }

        $allResults = [];
        
        foreach ($hotelCodes as $hotelCode) {
            $stmt = $this->db->prepare("
                SELECT hotel_code, description, path 
                FROM hotel_images 
                WHERE hotel_code = ? AND code = 'GEN' 
                LIMIT 1
            ");
            
            $stmt->bind_param('i', $hotelCode);
            $stmt->execute();
            
            $result = $stmt->get_result()->fetch_assoc();
            
            if ($result) {
                $allResults[] = $result;
            }
            
            $stmt->close();
        }
        
        $groupedResults = $this->groupByHotelCode($allResults);

        // Cache the result
        $this->cache->set($cacheKey, $groupedResults, 3600);
        
        return $groupedResults;
    }

    private function fetchLimitedFacilities(array $hotelCodes, int $groupCode, int $number, int $limit): array
    {
        if (empty($hotelCodes)) return [];

        //error_log("Fetching limited facilities for hotels: " . implode(", ", $hotelCodes));

        // Generate cache key
        sort($hotelCodes); // Sort for consistent cache keys
        $cacheKey = "limited_facilities_" . implode("_", $hotelCodes) . "_g{$groupCode}_l{$limit}";

        // Try to get from cache first
        $cachedData = $this->cache->get($cacheKey);
        if ($cachedData !== null) {
            return $cachedData;
        }

        $allResults = [];
        
        foreach ($hotelCodes as $hotelCode) {
            $stmt = $this->db->prepare("
                SELECT hotel_code, description 
                FROM hotel_facilities 
                WHERE hotel_code = ? AND group_code = ? 
                LIMIT ?
            ");
            
            $stmt->bind_param('iii', $hotelCode, $groupCode, $limit);
            $stmt->execute();
            
            $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $allResults = array_merge($allResults, $result);
            
            $stmt->close();
        }
        
        $groupedResults = $this->groupByHotelCode($allResults);

        // Store in cache for 1 hour (3600 seconds)
        $this->cache->set($cacheKey, $groupedResults, 3600);
        //error_log("Stored limited facilities in cache with key: " . $cacheKey);
        
        return $groupedResults;
    }

    private function groupByHotelCode(array $data): array
    {
        $grouped = [];
        foreach ($data as $item) {
            $hotelCode = $item['hotel_code'];
            unset($item['hotel_code']);
            $grouped[$hotelCode][] = $item;
        }
        return $grouped;
    }

    public function getRoomFacilitiesByHotelAndRooms($hotelCode, $roomCodes)
    {
        try {
            // Validate inputs
            if (empty($hotelCode) || empty($roomCodes)) {
                throw new InvalidArgumentException("Hotel code and room codes are required");
            }

            // Convert comma-separated room codes to array and validate
            $roomCodeArray = explode(',', $roomCodes);
            //error_log("Room codes for facilities: " . implode(", ", $roomCodeArray));
            $roomCodeArray = array_filter($roomCodeArray, function($code) {
                return !empty(trim($code));
            });

            if (empty($roomCodeArray)) {
                throw new InvalidArgumentException("No valid room codes provided");
            }

            // Generate cache key
            $cacheKey = $this->generateCacheKey($hotelCode, $roomCodeArray);
            
            // Try to get from cache first
            $cachedData = $this->cache->get($cacheKey);
            if ($cachedData !== null) {
                return $cachedData;
            }

            // Create placeholders for prepared statement
            $roomPlaceholders = implode(',', array_fill(0, count($roomCodeArray), '?'));
            
            // Prepare the SQL query
            $sql = "
                SELECT 
                    hotel_code,
                    room_code,
                    description,
                    groupcode,
                    indfee,
                    indlogic,
                    indyesorno,
                    number
                FROM hotel_room_facility 
                WHERE hotel_code = ? 
                AND room_code IN ($roomPlaceholders)
            ";

            $stmt = $this->db->prepare($sql);
            
            if (!$stmt) {
                throw new RuntimeException("Failed to prepare statement: " . $this->db->error);
            }

            // Bind parameters
            $types = 'i' . str_repeat('s', count($roomCodeArray)); // hotel_code (i) + room codes (i*i)
            $params = array_merge([$hotelCode], $roomCodeArray);
            
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            
            $result = $stmt->get_result();
            $facilities = $result->fetch_all(MYSQLI_ASSOC);
            
            $stmt->close();

            // Group facilities by room code for easier consumption
            $groupedFacilities = [];
            foreach ($facilities as $facility) {
                $roomCode = $facility['room_code'];
                unset($facility['room_code']);
                $groupedFacilities[$roomCode][] = $facility;
            }

            return  $groupedFacilities;
            
        } catch (Exception $e) {
            sendErrorResponse(500, [
                'success' => false,
                'errors' => [
                    [
                        'title' => 'Failed to fetch room facilities',
                        'detail' => $e->getMessage()
                    ]
                ],
                'data' => [],
            ]);
            exit;
        }
    }

    public function getRoomImagesByHotelAndRooms($hotelCode, $roomCodes): array
    {

        try{
            //error_log("Hotel code: $hotelCode, Room codes: $roomCodes");
            if (empty($hotelCode) || empty($roomCodes)) {
                throw new InvalidArgumentException("Hotel code and room codes are required");
            }

            // Generate cache key
            $roomCodeArray = explode(',', $roomCodes);
            //error_log("Room codes for images: " . implode(", ", $roomCodeArray));
            $cacheKey = "room_images_$hotelCode" . "_" . implode("_", $roomCodeArray);

             if (empty($roomCodeArray)) {
                throw new InvalidArgumentException("No valid room codes provided");
            }

            // Try cache first
            $cachedData = $this->cache->get($cacheKey);
            if ($cachedData !== null) {
                return $cachedData;
            }

            $roomPlaceholders = implode(',', array_fill(0, count($roomCodeArray), '?'));
            
            $stmt = $this->db->prepare("
                SELECT hotel_code, path, roomcode, description
                FROM hotel_images 
                WHERE hotel_code = ?
                AND roomcode IN ($roomPlaceholders)
            ");

            $types = 'i' . str_repeat('s', count($roomCodeArray)); // hotel_code (i) + room codes (i*i)
            $params = array_merge([$hotelCode], $roomCodeArray);
            
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            
            $images = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            $groupedImages = [];
            foreach ($images as $image) {
                $roomCode = $image['roomcode']; // Use roomcode as the key for grouping images by roomfacility['roomcode'];
                unset($image['roomcode']);
                $groupedImages[$roomCode][] = $image;
            }

            // Cache the result
            $this->cache->set($cacheKey, $groupedImages, 3600);

            //error_log("Room images: " . json_encode($groupedImages));
            
            return $groupedImages;

        } catch (Exception $e) {
            sendErrorResponse(500, [
                'success' => false,
                'errors' => [
                    [
                        'title' => 'Failed to fetch room images',
                        'detail' => $e->getMessage()
                    ]
                ],
                'data' => [],
            ]);
            exit;
        }
    }

    private function generateCacheKey($hotelCode, $roomCodeArray){
        sort($roomCodeArray); // Sort to ensure consistent cache keys
        return "room_facilities_{$hotelCode}_" . md5(implode('_', $roomCodeArray));
    }

   public function getHotelFacilities($hotelCode){
        try {
            if (empty($hotelCode)) {
                throw new InvalidArgumentException("Hotel code is required");
            }

            $cacheKey = "hotel_facilities_{$hotelCode}";
            
            // Try cache first
            $cachedData = $this->cache->get($cacheKey);
            if ($cachedData !== null) {
                return $cachedData;
            }
            
            $stmt = $this->db->prepare("
                SELECT hotel_code, description, group_code, indfee, indlogic, indyesorno, number, distance 
                FROM hotel_facilities 
                WHERE hotel_code = ? 
                ORDER BY group_code, number
            ");
            
            $stmt->bind_param('i', $hotelCode);
            $stmt->execute();
            
            $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // Cache for 1 hour
            $this->cache->set($cacheKey, $result, 3600);
            
            return $result;

        } catch (Exception $e) {
            sendErrorResponse(500, [
                'success' => false,
                'errors' => [
                    [
                        'title' => 'Failed to fetch hotel facilities',
                        'detail' => $e->getMessage()
                    ]
                ],
                'data' => [],
            ]);
            exit;
        }
    }



    public function getCheckRate($api_key, $signature, $payload) {

        $certPath = $_SERVER['DOCUMENT_ROOT'] . CRTFILE;
        $keyPath = $_SERVER['DOCUMENT_ROOT'] . KEYFILE;
        $caPath = $_SERVER['DOCUMENT_ROOT'] . CAFILE;

        $page = $payload['pagination']['page'] ?? 1;
        $pageSize = $payload['pagination']['pageSize'] ?? 6;

        //error_log("=== API REQUEST DEBUG ===");
        //error_log("Payload: " . print_r($payload, true));

        // Validate paths
        foreach ([$certPath, $keyPath, $caPath] as $file) {
            if (!file_exists($file)) {
                throw new RuntimeException("File not found: $file");
            }
        }

        $jsonPayload = json_encode($payload);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON: " . json_last_error_msg());
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.test.hotelbeds.com/hotel-api/1.2/checkrates",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSLCERT => $certPath,
            CURLOPT_SSLKEY => $keyPath,
            CURLOPT_CAINFO => $caPath,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "Api-key: $api_key",
                "X-Signature: $signature",
                "Accept: application/json",
                "Content-Type: application/json",
                "Accept-Encoding: gzip"
            ],
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_VERBOSE => true,
            CURLOPT_ENCODING => 'gzip'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("cURL error: " . $error);
        }

         // Log the response for debugging
        $data = json_decode($response, true) ?? [];

        error_log("Rate Comments: " . print_r($data, true));
        
        if ($httpCode !== 200) {
            $errorMsg = $data['error'] ?? $response ?? 'Unknown error';
            //throw new RuntimeException("API error ($httpCode): " . $errorMsg);
            sendErrorResponse($httpCode, [
                'success' => false,
                'errors' => [
                    [
                        'title' => 'Internal Server Error',
                        'detail' => $errorMsg
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

        

        return $data;
    }

    public function fetchHotelInfoByCode($hotel_code) {
        $sql = "
            SELECT h.*, hi.path AS main_image_path
            FROM hotels h
            LEFT JOIN hotel_images hi 
                ON hi.hotel_code = h.code 
                AND hi.code = 'GEN'
            WHERE h.code = ?
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $hotel_code);
        $stmt->execute();

        $result = $stmt->get_result();
        $hotelInfo = $result->fetch_assoc();

        $stmt->close();

        return $hotelInfo ?? null;
    }
    

    public function bookHotel($api_key, $signature, $postData){
        // This function would handle the booking process using the provided postData
        try{

            $certPath = $_SERVER['DOCUMENT_ROOT'] . CRTFILE;
            $keyPath = $_SERVER['DOCUMENT_ROOT'] . KEYFILE;
            $caPath = $_SERVER['DOCUMENT_ROOT'] . CAFILE;
           
            $requiredFields = ['total_amount', 'rateKey', 'holderFirstName', 'holderLastName', 'holderEmail', 'rooms'];
            foreach ($requiredFields as $field) {
                if (!isset($postData[$field])) {
                    throw new InvalidArgumentException("Missing required field: " . $field);
                }
            }

            if (intval($postData['rooms']) <= 0) {
                throw new InvalidArgumentException("Rooms count must be a positive integer");
            }

            $paxes = $this->buildPaxesFromPostData($postData);

            $order_items_html = $paxes['order_items_html'];
            $paxes = $paxes['paxes'];

            $hotel_name = $postData['hotelName'] ?? '';
            $dest_name = $postData['destName'] ?? '';
            $category = $postData['category'] ?? '';
            $address = $postData['address'] ?? '';
            $phone = $postData['phone'] ?? '';
            $holder_firstname = $postData['holderFirstName'] ?? '';
            $holder_lastname = $postData['holderLastName'] ?? '';
            $holder_email = $postData['holderEmail'] ?? '';
            $checkin = $postData['checkIn'] ?? '';
            $checkout = $postData['checkOut'] ?? '';
            $room_name = $postData['roomName'] ?? '';
            $board_name = $postData['boardName'] ?? '';
            $room = intval($postData['rooms'] ?? 0);
            $rate_comment = $postData['rateComment'] ?? '';
            $reference = $postData['bookingReference'] ?? '';
            $client_reference = "IntegrationAgency";
            $rate_key = $postData['rateKey'] ?? '';
            $total_amount = $postData['total_amount'] ?? 0;
            $currency = $postData['currency'] ?? '';
            $holder_remark = $postData['special_requests'] ?? '';
            $payment_type = $postData['payment_type'] ?? 'paynow';
            $payment_method = $postData['payment_method'] ?? '';
            $platform_type = $postData['platform_type'];
            $user = $postData['user'] ?? '';

            if($platform_type == 'smallyfares' && $payment_method == 'wallet'){
                $wallet_balance = $this->userModel->fetchuserinfo($user)['wallet'] ?? 0;
                if($wallet_balance < $total_amount){
                    throw new Exception("Insufficient wallet balance");
                }
            }

            if($platform_type !== 'smallyfares'){
                 $wallet_balance = $this->userModel->fetchuserinfo($api_key)['wallet'] ?? 0;
                    error_log("Wallet Balance: " . $wallet_balance);
                    if($wallet_balance < $total_amount){
                        throw new Exception("Insufficient wallet balance");
                    }
            }
            

            $email_template = file_get_contents($this->emailTemplate);
            $email_template = str_replace('[Hotel Name]', $hotel_name, $email_template);
            $email_template = str_replace('[Destination]', $dest_name, $email_template);
            $email_template = str_replace('[Hotel Category]', $category, $email_template);
            $email_template = str_replace('[Hotel Address]', $address, $email_template);
            $email_template = str_replace('[Contact]', $phone, $email_template);
            $email_template = str_replace('[Guest Email]', $holder_email, $email_template);
            $email_template = str_replace('[Guest Name]', $holder_firstname.' '.$holder_lastname, $email_template);
            $email_template = str_replace('[Checkin]', $checkin, $email_template);
            $email_template = str_replace('[Checkout]', $checkout, $email_template);
            $email_template = str_replace('[Room type]', $room_name, $email_template);
            $email_template = str_replace('[Board Type]', $board_name, $email_template);
            $email_template = str_replace('[Rooms]', $room, $email_template);
            $email_template = str_replace('[Rate Comment]', '', $email_template);
            $email_template = str_replace('[Reference]', $reference, $email_template);
            $email_template = str_replace('[Occupancy]', $order_items_html, $email_template);
           
            
            //$send = $this->sendmail($holder_email,$holder_firstname.' '.$holder_lastname,$email_template,"Booking information");
        /* if (count($paxes) !== $totalRooms) {
                throw new InvalidArgumentException("Pax count does not match rooms count");
            }*/

            $post = [
                "holder" => [
                    "name" => $holder_firstname,
                    "surname" => $holder_lastname
                ],
                "rooms" => [
                    [
                        "rateKey" => $rate_key,
                        "paxes" => $paxes
                    ]
                ],
                "clientReference" => $client_reference,
                "remark" => $holder_remark
            ];

            $json_query = json_encode($post, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "https://api-mtls.hotelbeds.com/hotel-api/1.0/bookings",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSLCERT => $certPath,
                CURLOPT_SSLKEY => $keyPath,
                CURLOPT_CAINFO => $caPath,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    "Api-key: $api_key",
                    "X-Signature: $signature",
                    "Accept: application/json",
                    "Content-Type: application/json",
                    "Accept-Encoding: gzip"
                ],
                CURLOPT_POSTFIELDS => $json_query,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_VERBOSE => true,
                CURLOPT_ENCODING => 'gzip'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            //error_log("Booking data: " . json_encode($post, true));
            if($error) {
                throw new RuntimeException("cURL error: " . $error);
            }

            if ($httpCode !== 200) {
                $data = json_decode($response, true);
                $errorMsg = $data['error']['message'] ?? $response ?? 'Unknown error';
                throw new RuntimeException("API error ($httpCode): " . $errorMsg);
            }

            $data = json_decode($response, true) ?? [];

            $reference = $data['booking']['reference'];
            $status = $data['booking']['status'];
            $vat = $data['booking']['hotel']['supplier']['vatNumber'] ?? "VVV";
            $xxx = $data['booking']['hotel']['supplier']['name'] ?? "XXX";
            $total_nights = strtotime($checkout) - strtotime($checkin) ?? 0;
            $total_nights = floor($total_nights / (60 * 60 * 24));

            $prepare_json = [
                'hotel_name' => $hotel_name,
                'total_nights' => $total_nights,
                'total_guests' => count($paxes),
            ];

            $remarks = "Payable through $xxx, acting as agent for the service operating company, details of which can be provided upon request. VAT: $vat Reference: $reference";
            $remarks .= "<p>The hotel is responsible for the remarks</p>";
            $email_template = str_replace('[Remark]', $remarks, $email_template);

            $this->userModel->sendmail($holder_email,$holder_firstname.' '.$holder_lastname,$email_template,"Booking information");

            $id = $this->userModel->InsertBookings(
                $holder_email,
                $holder_firstname,
                $holder_lastname,
                $reference,
                $phone,
                date('Y-m-d H:i:s'),
                $status,
                'international',
                'hotel',
                $api_key,
                $payment_type,
                $payment_method,
                $total_amount,
                json_encode($prepare_json)
            );

            if($platform_type == 'smallyfares' && $payment_method == 'wallet'){

                $user_id = $this->userModel->fetchuserinfo($user)['id'] ?? 0;
                $hotel_margin = $this->userModel->fetchuserinfo($user)['hotel_margin'] ?? 0;
                $calcPercentage = $this->userModel->calculatePercentage($total_amount, $hotel_margin);
                $this->userModel->InsertWalletTransactions(
                    $user_id,
                    'debit',
                    $total_amount,
                    $calcPercentage,
                    'Hotel Booking - '.$reference,
                    date('Y-m-d H:i:s')
                );

                $this->userModel->updateWalletBalance($user, $total_amount - $calcPercentage);
            }

            if($platform_type !== 'smallyfares'){

                $user_details = $this->userModel->fetchuserinfo($api_key)['id'] ?? 0;
                $hotel_margin = $user_details['hotel_margin'] ?? 0;
                $user_id = $user_details['id'] ?? 0;
                $calcPercentage = $this->userModel->calculatePercentage($total_amount, $hotel_margin);
                $this->userModel->InsertWalletTransactions(
                    $user_id,
                    'debit',
                    $total_amount,
                    $calcPercentage,
                    'Hotel Booking - '.$reference,
                    date('Y-m-d H:i:s')
                );

                $this->userModel->updateWalletBalance($user, $total_amount - $calcPercentage);

            }

            return [
                'status' => true,
                'data' => $data,
                'message' => 'Booking successful',
                'booking_reference' => $reference,
                'status' => $status,
                'booking_id' => $id
            ];
            
        } catch (InvalidArgumentException $e) {
            //error_log("Booking error: " . $e->getMessage());
            sendErrorResponse(403, [
                'errors' => [
                    [
                        'title' => 'Invalid Request',
                        'detail' => $e->getMessage()
                    ]
                ]
            ]);

        } catch (Exception $e) {

            sendErrorResponse(403, [
                'errors' => [
                    [
                        'title' => 'Error Processing Booking',
                        'detail' => $e->getMessage()
                    ]
                ]
            ]);
        }
    }

    private function buildPaxesFromPostData($postData) {
        $paxes = [];
        $validationErrors = [];
        $order_items_html = '';
        
        // Find all unique room numbers
        $roomNumbers = $this->extractRoomNumbers($postData);
        
        foreach ($roomNumbers as $roomId) {
            // Process adults
            $this->processRoomGuests($postData, $roomId, 'adult', $paxes, $validationErrors, $order_items_html);
            
            // Process children
            $this->processRoomGuests($postData, $roomId, 'child', $paxes, $validationErrors, $order_items_html);
        }
        
        // Throw validation errors if any
        if (!empty($validationErrors)) {
            $this->throwValidationError($validationErrors, $roomNumbers, $paxes);
        }
        
        return ['paxes' => $paxes, 'order_items_html' => $order_items_html];
    }

    private function extractRoomNumbers($postData) {
        $roomNumbers = [];
        foreach (array_keys($postData) as $key) {
            if (preg_match('/^room(\d+)_/', $key, $matches)) {
                $roomNumbers[$matches[1]] = true;
            }
        }
        $roomNumbers = array_keys($roomNumbers);
        sort($roomNumbers);
        return $roomNumbers;
    }

    private function processRoomGuests($postData, $roomId, $guestType, &$paxes, &$validationErrors, &$order_items_html) {
        $guestTypeLower = strtolower($guestType);
        $guestTypeUpper = strtoupper($guestTypeLower === 'child' ? 'CH' : 'AD');
        $count = 1;
        
        while ($this->guestExists($postData, $roomId, $guestTypeLower, $count)) {
            $firstName = trim($postData["room{$roomId}_{$guestTypeLower}{$count}_firstName"] ?? '');
            $lastName = trim($postData["room{$roomId}_{$guestTypeLower}{$count}_lastName"] ?? '');
            $type = trim($postData["room{$roomId}_{$guestTypeLower}{$count}_type"] ?? '');
            
            $errors = [];
            
            if (empty($firstName)) {
                $errors[] = "First name is required";
            }
            
            if (empty($lastName)) {
                $errors[] = "Last name is required";
            }
            
            if (empty($type) || $type !== $guestTypeUpper) {
                $errors[] = "Type must be '{$guestTypeUpper}'";
            }
            
            // Additional validation for children
            if ($guestTypeLower === 'child') {
                $age = trim($postData["room{$roomId}_{$guestTypeLower}{$count}_age"] ?? '');
                if (empty($age) || $age === 'undefined') {
                    $errors[] = "Age is required for children";
                } elseif (!is_numeric($age) || $age < 0 || $age > 17) {
                    $errors[] = "Age must be a number between 0 and 17";
                }
            }
            
            if (!empty($errors)) {
                $guestLabel = ucfirst($guestTypeLower) . " {$count}";
                $validationErrors[] = [
                    'room' => $roomId,
                    'guest' => $guestLabel,
                    'errors' => $errors
                ];
            } else {
                
                if($guestTypeLower === 'child') {

                    $pax = [
                        'roomId' => (int)$roomId,
                        'type' => $type,
                        'name' => $firstName,
                        'surname' => $lastName,
                        'age' => $postData["room{$roomId}_{$guestTypeLower}{$count}_age"]
                    ];

                    $order_items_html .= '<tr><td style="padding: 10px; border: 1px solid #ddd; text-align: left;">'.$firstName.'</td><td style="padding: 10px; border: 1px solid #ddd; text-align: left;">'.$lastName.'</td><td style="padding: 10px; border: 1px solid #ddd; text-align: left;">'.$age.'</td></tr>';
			

                } else {

                    $pax = [
                        'roomId' => (int)$roomId,
                        'type' => $type,
                        'name' => $firstName,
                        'surname' => $lastName
                    ];

                    $order_items_html .= '<tr><td style="padding: 10px; border: 1px solid #ddd; text-align: left;">'.$firstName.'</td><td style="padding: 10px; border: 1px solid #ddd; text-align: left;">'.$lastName.'</td><td style="padding: 10px; border: 1px solid #ddd; text-align: left;">Adult</td></tr>';
			
                }
                
                $paxes[] = $pax;
            }
            
            $count++;
        }
    }

    private function guestExists($postData, $roomId, $guestType, $count) {
        $fields = ['firstName', 'lastName', 'type'];
        if ($guestType === 'child') {
            $fields[] = 'age';
        }
        
        foreach ($fields as $field) {
            $key = "room{$roomId}_{$guestType}{$count}_{$field}";
            if (isset($postData[$key])) {
                return true;
            }
        }
        
        return false;
    }

    private function throwValidationError($validationErrors, $roomNumbers, $paxes) {
        $errorMessages = ["Please complete all guest information:"];
        
        foreach ($validationErrors as $error) {
            $errorMessages[] = sprintf(
                "• Room %d, %s: %s",
                $error['room'],
                $error['guest'],
                implode(', ', $error['errors'])
            );
        }
        
        // Add room occupancy summary
        /*$errorMessages[] = "";
        $errorMessages[] = "Current room occupancy:";
        foreach ($roomNumbers as $roomId) {
            $adults = 0;
            $children = 0;
            foreach ($paxes as $pax) {
                if ($pax['roomId'] == $roomId) {
                    if ($pax['type'] == 'AD') $adults++;
                    if ($pax['type'] == 'CH') $children++;
                }
            }
            $errorMessages[] = "Room {$roomId}: {$adults} adult(s), {$children} child(ren)";
        }*/
        
        throw new InvalidArgumentException(implode("\n", $errorMessages));
    }

    public function fetchHotelBookingDetails($bookingReference) {
        // For demonstration, returning a hardcoded JSON string
        $bookingData ='{"booking_code":"29992903","booking_reference":"GO27804454-29992903-A(INT)","client_booking_code":"REF_1763498296_8901","booking_status":"C","total_price":"1072.00","currency":"USD","check_in":"2025-12-17","check_out":"2025-12-19","nights":2,"cancellation_deadline":"2025-12-10","hotel_name":"CASCADE WELLNESS AND LIFESTYLE RESORT","hotel_id":"168796","city_code":"1032","room_basis":"RO","address":"Rua das Ilhas, Lagos, PT","leader":{"name":"AZEEZ SHINA","person_id":"1"},"rooms":[{"type":"","adults_count":1,"rooms":[{"room_id":"1","category":"Two Beedroom Pool View *","adults":[{"person_id":"1","first_name":"AZEEZ","last_name":"SHINA","title":"MR."}],"children":[{"person_id":"2","child_age":"10","first_name":"AZEEMAH","last_name":"FALETI","type":"extra_bed"}],"total_adults":1,"total_children":1},{"room_id":"2","category":"One Bedroom Apartment *","adults":[{"person_id":"3","first_name":"FALETI","last_name":"SULAIMON","title":"MR."}],"children":[{"person_id":"4","child_age":"13","first_name":"SEMIU","last_name":"FALETI","type":"extra_bed"}],"total_adults":1,"total_children":1}]}],"remarks":"<BR \/>EXTRA BED FOR CHILDREN IS NOT GUARANTEED.<BR \/>IF YOU FAIL TO CHECK-IN FOR THIS RESERVATION, OR IF YOU CANCEL OR CHANGE THIS RESERVATION AFTER CXL DEADLINE, YOU MAY INCUR PENALTY CHARGES AT THE DISCRETION OF THE HOTEL OF UP TO 100% OF THE BOOKING VALUE, UNLESS OTHERWISE STATED.CXL charges apply as follows: STARTING 10\/12\/2025 CXL-PENALTY FEE IS 100.00% OF BOOKING PRICE.<BR \/>STARTING 15\/12\/2025 CXL-PENALTY FEE IS 100.00% OF BOOKING PRICE., <p>* 29\/09\/2025 - 05\/01\/2026: We inform that in Cascade Wellness Resort, from 08th December 2025 to 1st  January 2026 (inclusive), some facilities will be temporarily closed, namely: the rooms in the hotel building, the restaurants, the bar, the spa, and the kids", "total_rooms":1,"summary":{"total_adults":2,"total_children":2}}';

        return json_decode($bookingData, true);
    }
}