<?php
// /home/myzonaco/smartpark.myzona360.com/config/ocr.php
// Configuración del servicio de OCR de placas.
//
// SmartPark usa PlateRecognizer.com (https://platerecognizer.com)
//   - Plan Free: 2,500 lecturas/mes gratis (perfecto para arrancar)
//   - 1) Crea cuenta en https://platerecognizer.com/accounts/signup/
//   - 2) Confirma email y entra a https://app.platerecognizer.com/api-keys/
//   - 3) Copia tu API Token y pégalo abajo en OCR_API_TOKEN
//
// El servicio devuelve JSON con la placa detectada y el score de confianza.

if (!defined('SMARTPARK_BOOT')) {
    http_response_code(403);
    exit('Forbidden');
}

// ⚠️ REEMPLAZA ESTE VALOR con tu token real de PlateRecognizer
define('OCR_API_TOKEN', '6901983b733649d3efc1fd9f0868cc6cbc84e6ae');

// Endpoint del API (snapshot cloud)
define('OCR_API_URL', 'https://api.platerecognizer.com/v1/plate-reader/');

// Regiones que prioriza el detector (mejora precisión para Colombia)
define('OCR_REGIONS', 'co');

// Confianza mínima aceptable (0.0 - 1.0). Por debajo de esto se muestra advertencia.
define('OCR_MIN_CONFIDENCE', 0.70);

// Timeout en segundos para la llamada al API
define('OCR_TIMEOUT', 15);

// ¿Está activo el OCR? Se desactiva automáticamente si no hay token configurado.
define('OCR_ENABLED', OCR_API_TOKEN !== 'PEGA_AQUI_TU_TOKEN_DE_PLATERECOGNIZER' && OCR_API_TOKEN !== '');
