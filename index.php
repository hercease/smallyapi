<?php
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Load config and allcontrollers
require_once('config/config.php');
require_once('app/controllers/flight_controller.php');
require_once('app/controllers/user_controller.php');
require_once('app/controllers/db_controller.php');
require_once('app/controllers/hotel_controller.php');
require_once('app/models/flight_model.php');
require_once('app/models/hotel_model.php');
require_once('app/models/user_model.php');

// Handle routing
$baseDir = '/smallyapi';  // Base directory where your app is located
$url = str_replace($baseDir, '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
// Initialize database
$db = (new Database())->connect();
$FlightController = new FlightController($db);
$HotelController = new HotelController($db);
$UserController = new UserController($db);

//error_log("Requested URL: " . print_r($_POST, true));

switch ($url) {
    case '/onewayflight':
        $FlightController->getOneWayFlight();
        break;
    case '/searchhotels':
        $HotelController->searchHotels();
        break;
    case '/searchhoteldestinations':
        $HotelController->searchHotelDestinations();
        break;
    case '/hoteldetails':
        $HotelController->Hoteldetails();
        break;
    case '/hotel-facilities':
        $HotelController->fetchHotelFacilities();
        break;
    case '/room-facilities':
        $HotelController->fetchRoomFacilities();
        break;
    case '/rate-comments':
        $HotelController->fetchCheckRate();
        break;
    case '/add-room-to-cart':
        $HotelController->addRoomToCart();
        break;
    case '/get-cart':
        $HotelController->fetchCart();
        break;
    case '/remove-cart-item':
        $HotelController->removeCartItem();
        break;
    case '/fetch-cart-by-id':
        $HotelController->fetchCartById();
        break;
    case '/book-hotel':
        $HotelController->bookHotel();
        break;
    case '/create_stripe_payment_intent':
        $HotelController->stripePaymentIntent();
        break;
    case '/confirm_stripe_payment':
        $HotelController->confirmPayment();
        break;
    case '/send_verification_code':
        $UserController->sendVerificationCode();
        break;
    case '/createuser':
        $UserController->createUser();
        break;
    case '/fetch_user_data':
        $UserController->fetchUserData();
        break;
    case '/loginuser':
        $UserController->loginUser();
        break;
    case '/booking_details':
        $HotelController->HotelBookingDetails();
        break;
    case '/user_bookings':
        $UserController->UserBookings();
        break;
    default:
    sendErrorResponse(404, [
        'errors' => [
            [
                'title' => 'Method Not Allowed',
                'detail' => 'Route Does Not Exist'
            ]
        ]
    ]);
}

/**
 * Send a JSON success response.
 *
 * @param array $data Response data
 * @return void
 */
function sendSuccessResponse(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Send a JSON error response.
 *
 * @param int $statusCode HTTP status code
 * @param string $message Error message
 * @return void
 */
function sendErrorResponse(int $statusCode, array $message): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($message);
    exit;
}
?>