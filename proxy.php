<?php
// ====================================================================================================
// CONFIGURACIÓN DE CABECERAS CORS - DEBEN IR AL PRINCIPIO PARA EVITAR PROBLEMAS
// ====================================================================================================
// Configurar encabezados CORS para permitir peticiones desde cualquier origen (para desarrollo)
// En producción, considera restringir esto solo a tu dominio para mayor seguridad:
// header("Access-Control-Allow-Origin: https://tudominio.com");

// Permite cualquier origen, incluyendo 'null' para pruebas locales (file://)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json"); // Asegurar que la respuesta sea JSON

// Manejar peticiones OPTIONS (pre-flight requests de CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ====================================================================================================
// CONFIGURACIÓN DE PARES DE CLAVES API DE OPENROUTESERVICE
// ====================================================================================================
// Define un array de claves API de OpenRouteService.
// Puedes añadir tantas claves como necesites.
// ¡MUY IMPORTANTE! Reemplaza 'TU_CLAVE_API_DE_OPENROUTESERVICE_AQUI'
// con tus claves API reales para cada entrada.
// El proxy intentará usar estas claves en orden si una falla con HTTP 429.
$orsApiKeys = [
    '5b3ce3597851110001cf62482122c0bc9aaa445d86ca355c97318573', // ¡REEMPLAZA ESTO CON TU CLAVE REAL!
     '5b3ce3597851110001cf62480922366d108644738c2ba5169ab71c73', // Clave 2
    // 'clave_api_3' // Clave 3
    // Añade más claves según sea necesario
];

// Set up error logging for uncaught exceptions
set_exception_handler(function ($exception) {
    // Log the exception details to the server's error log
    error_log("Uncaught Exception in proxy.php: " . $exception->getMessage() . " on line " . $exception->getLine() . " in " . $exception->getFile());
    
    // Return a generic error to the client to avoid exposing internal details
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error. Please check server logs for more details.', 'debug_message' => $exception->getMessage()]);
    exit();
});

// Obtener los parámetros de la solicitud POST
$input = file_get_contents('php://input');
$requestData = json_decode($input, true);

// Verificar que los datos de la solicitud sean válidos
if (!$requestData || !isset($requestData['action'])) {
    echo json_encode(['error' => 'Acción no especificada o datos inválidos.']);
    http_response_code(400); // Bad Request
    exit();
}

$action = $requestData['action'];
$responseData = [];
$lastError = null;

// Iterar a través de las claves para intentar la solicitud
foreach ($orsApiKeys as $keyIndex => $currentKey) {
    try {
        switch ($action) {
            case 'geocode':
                if (!isset($requestData['query'])) {
                    throw new Exception('Falta el parámetro "query" para la geocodificación.');
                }
                $query = urlencode($requestData['query']);
                $url = "https://api.openrouteservice.org/geocode/autocomplete?api_key={$currentKey}&text={$query}&boundary.country=ESP&size=5&lang=es";
                $responseData = makeApiRequest($url);
                break;

            case 'route':
            case 'direct_distance':
                if (!isset($requestData['coordinates'])) {
                    throw new Exception('Faltan las coordenadas para el enrutamiento.');
                }
                
                $postData = ['coordinates' => $requestData['coordinates'], 'geometry' => true];

                $url = "https://api.openrouteservice.org/v2/directions/driving-car/geojson";
                $postBody = json_encode($postData);
                $headers = ['Authorization: ' . $currentKey, 'Content-Type: application/json'];
                $responseData = makeApiRequest($url, 'POST', $postBody, $headers);
                break;
            
            case 'optimization':
                // Asegúrate de que los datos necesarios para la optimización estén presentes: 'vehicles' y ( 'jobs' o 'shipments' )
                // Se ha modificado para aceptar tanto 'jobs' como 'shipments'
                if (!isset($requestData['vehicles']) || (!isset($requestData['jobs']) && !isset($requestData['shipments']))) {
                    throw new Exception('Faltan los parámetros "vehicles" y/o "jobs" o "shipments" para la optimización.');
                }
                
                $url = "https://api.openrouteservice.org/optimization";
                
                // Construye el cuerpo de la solicitud para la API de ORS, incluyendo solo los parámetros relevantes.
                $orsPayload = [
                    'vehicles' => $requestData['vehicles']
                ];
                if (isset($requestData['jobs'])) {
                    $orsPayload['jobs'] = $requestData['jobs'];
                } elseif (isset($requestData['shipments'])) {
                    $orsPayload['shipments'] = $requestData['shipments'];
                }
                // Si tienes otros parámetros como 'options', etc., puedes añadirlos aquí.
                // foreach(['options', 'services', 'break', 'replenish', 'relations'] as $key) {
                //     if (isset($requestData[$key])) {
                //         $orsPayload[$key] = $requestData[$key];
                //     }
                // }


                $postBody = json_encode($orsPayload);
                $headers = ['Authorization: ' . $currentKey, 'Content-Type: application/json'];
                $responseData = makeApiRequest($url, 'POST', $postBody, $headers);
                break;

            default:
                throw new Exception('Acción no reconocida.');
        }
        // Si la solicitud fue exitosa, salimos del bucle y devolvemos la respuesta
        echo json_encode($responseData);
        exit();

    } catch (Exception $e) {
        // Almacenamos el error actual. Si todas las claves fallan, este será el último error reportado.
        $lastError = ['error' => $e->getMessage() . " (Fallo con clave " . ($keyIndex + 1) . "/" . count($orsApiKeys) . ")."];

        // No salimos si no es la última clave, para intentar con la siguiente.
        // Solo si es la última clave y ha fallado, entonces sí terminamos la ejecución.
        if ($keyIndex === count($orsApiKeys) - 1) {
            echo json_encode($lastError);
            http_response_code(500); // Internal Server Error
            exit();
        }
        // Si no es la última clave, el bucle foreach continuará automáticamente con la siguiente iteración.
    }
}

// Si llegamos aquí, significa que todas las claves fallaron y ya se devolvió un error,
// o que el array de claves estaba vacío.
// Esto actúa como un fallback final si el array de claves está vacío o si la lógica anterior no lo atrapó por alguna razón.
if (!isset($lastError)) {
    echo json_encode(['error' => 'No hay claves API de OpenRouteService configuradas o todas fallaron sin un error específico.']);
    http_response_code(500);
}
// El 'exit();' ya se hizo dentro del bucle si fue la última clave o si la respuesta fue exitosa.

// ====================================================================================================
// makeApiRequest FUNCTION (NO CAMBIA)
// ====================================================================================================
/**
 * Realiza una solicitud HTTP a una API externa utilizando cURL.
 * @param string $url La URL de la API.
 * @param string $method El método HTTP (GET, POST).
 * @param string|null $postBody El cuerpo de la solicitud POST.
 * @param array $headers Un array de encabezados HTTP.
 * @return mixed La respuesta decodificada de la API.
 * @throws Exception Si la solicitud cURL falla o devuelve un error HTTP >= 400.
 */
function makeApiRequest($url, $method = 'GET', $postBody = null, $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Devuelve la respuesta como cadena
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Tiempo máximo de espera en segundos

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // Log the raw response and HTTP code for debugging before closing cURL
    error_log("OpenRouteService API Response (HTTP {$http_code}): " . substr($response, 0, 1000)); // Log first 1000 chars

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        throw new Exception("Error de cURL: " . $error_msg);
    }

    curl_close($ch);

    // Si el código HTTP es 4xx o 5xx, lo tratamos como un error
    if ($http_code >= 400) {
        $error_details = json_decode($response, true);
        $api_error_message = '';

        // Intenta extraer el mensaje de error de la respuesta JSON
        if (is_array($error_details)) {
            if (isset($error_details['error']) && is_string($error_details['error'])) {
                $api_error_message = $error_details['error'];
            } elseif (isset($error_details['message']) && is_string($error_details['message'])) {
                $api_error_message = $error_details['message'];
            } elseif (isset($error_details['error_message']) && is_string($error_details['error_message'])) {
                $api_error_message = $error_details['error_message'];
            }
        }
        
        // Si no se pudo extraer un mensaje de error JSON, o si la respuesta no era JSON, usa la respuesta cruda
        if (empty($api_error_message) && is_string($response) && !empty($response)) {
            $api_error_message = "Respuesta cruda de la API: " . substr($response, 0, 500); // Limita a los primeros 500 caracteres
        } elseif (empty($api_error_message)) {
            $api_error_message = "Respuesta inesperada de la API.";
        }

        throw new Exception("Error HTTP {$http_code}: " . $api_error_message);
    }

    return json_decode($response, true);
}
?>


