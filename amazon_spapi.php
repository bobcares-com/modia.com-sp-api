<?php

$refresh_token = "xxxxxxxxxxxxxxxxxxxx";
$client_id = "xxxxxxxxxxxxxxxxxxxx";
$client_secret = "xxxxxxxxxxxxxxxxxxxx";


// Refresh the access token
function refreshAccessToken($client_id, $client_secret, $refresh_token)
{
  $postData = [
    'grant_type' => 'refresh_token',
    'client_id' => $client_id,
    'client_secret' => $client_secret,
    'refresh_token' => $refresh_token,
  ];

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, 'https://api.amazon.com/auth/o2/token');
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $response = curl_exec($ch);
  curl_close($ch);

  return json_decode($response, true)['access_token'];
}

$magentoBasePath = '/var/www/vhosts/public/';
require $magentoBasePath . '/app/bootstrap.php';

// Bootstrap Magento
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

// Generate the access token
$accessToken = refreshAccessToken($client_id, $client_secret, $refresh_token);

// Retrieve product collection
$productCollectionFactory = $objectManager->create('\Magento\Catalog\Model\ResourceModel\Product\CollectionFactory');
$productCollection = $productCollectionFactory->create();
$productCollection->addAttributeToSelect('*'); // You can select specific attributes if needed

// Filter the product collection to include only the specified SKUs
$skuFilter = ['CW1WH', 'CW1LA']; // Add SKUs of the products you want to test
$productCollection->addFieldToFilter('amazon_sku', ['in' => $skuFilter]);

// Prepare the feed payload for quantity update
$feedPayload = [];
foreach ($productCollection as $product) {
  $sku = $product->getData('amazon_sku');

  // Check if SKU is not empty
  if (!empty($sku)) {
    $stockItem = $product->getExtensionAttributes()->getStockItem();
    if ($stockItem !== null) {
      $stockQuantity = $stockItem->getQty();
    } else {
      // If stock item is not found, treat it as having zero stock
      $stockQuantity = 0;
    }

    $stockQuantity = 5;

    // Output product SKU and stock quantity
    //echo "SKU: $sku, Stock: $stockQuantity\n";

    // Store quantity updates for processing
    $feedPayload[] = [
      'sku' => $sku,
      'quantity' => $stockQuantity,
    ];
  }
}

// Construct the request headers
$header = [
  'x-amz-access-token: ' . $accessToken,
  'Content-Type: application/json',
];

// Construct the feed document
$feedDocument = [
  'header' => [
    'sellerId' => 'A33ZFW15ZO0RKP',
    'version' => '2.0',
    'issueLocale' => 'en_US'
  ],
  'messages' => []
];


$messageId = 1; // Initial message ID

foreach ($feedPayload as $item) {
  $sku = $item['sku'];
  $message = [
    'messageId' => $messageId++, // Increment message ID for each iteration
    'operationType' => 'UPDATE',
    'MessageType' => 'Inventory',
    'MarketplaceId' => ['ATVPDKIKX0DER'],
    'Message' => [
      'Inventory' => [$item]
    ]
  ];

  // Add the message to the feed document
  $feedDocument['messages'][] = $message;
}

// Convert the feed document to JSON
$jsonFeedDocument = json_encode($feedDocument);


// Creating feed document
$jsonFeedDocument = json_encode([
  'contentType' => 'application/json'
]);


// Create cURL handle
$ch = curl_init();

// Set cURL options
curl_setopt($ch, CURLOPT_URL, 'https://sellingpartnerapi-na.amazon.com/feeds/2021-06-30/documents');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonFeedDocument);
curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

// Execute cURL and get the response
$response = curl_exec($ch);

// Parse the response to extract the URL
$responseData = json_decode($response, true);

// Check for errors
if (curl_errno($ch)) {
  echo 'Curl error: ' . curl_error($ch);
} else {
  $uploadUrl = $responseData['url'];

  // Upload the feed document to the provided URL

  $chUpload = curl_init();
  curl_setopt($chUpload, CURLOPT_URL, $uploadUrl);
  curl_setopt($chUpload, CURLOPT_CUSTOMREQUEST, 'PUT');
  curl_setopt($chUpload, CURLOPT_POSTFIELDS, $jsonFeedDocument);
  curl_setopt($chUpload, CURLOPT_HTTPHEADER, ['Content-Type: application/json',]);
  curl_setopt($chUpload, CURLOPT_RETURNTRANSFER, true);

  $uploadResponse = curl_exec($chUpload);

  if ($uploadResponse !== false) {
    //echo 'Feed document uploaded successfully.';

    $feedDocumentId = $responseData['feedDocumentId'];

    // Construct the request payload
    $requestPayload = [
      'feedType' => 'POST_FLAT_FILE_PRICEANDQUANTITYONLY_UPDATE_DATA',
      'marketplaceIds' => ['ATVPDKIKX0DER'],
      'inputFeedDocumentId' => $feedDocumentId,
      //'feedOptions' => 'YOUR_FEED_OPTIONS',
    ];


    // Convert the request payload to JSON
    $jsonPayload = json_encode($requestPayload);

    // Set the request headers
    $headers = [
      'x-amz-access-token: ' . $accessToken,
      'Content-Type: application/json',
    ];

    // Create cURL handle
    $chFeedId = curl_init();

    // Set cURL options
    curl_setopt($chFeedId, CURLOPT_URL, 'https://sellingpartnerapi-na.amazon.com/feeds/2021-06-30/feeds');
    // curl_setopt($chFeedId, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($chFeedId, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($chFeedId, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($chFeedId, CURLOPT_RETURNTRANSFER, true);

    // Execute cURL and get the response
    $response = curl_exec($chFeedId);
    $responseData = json_decode($response, true);

    if (curl_errno($ch)) {
      echo 'Curl error: ' . curl_error($ch);
    } else {
      $feedId = $responseData['feedId'];
      //echo "Feed ID: $feedId\n";
    }

    curl_close($chFeedId);

    if (isset($feedId)) {
      do {
        sleep(60);
        $feedDetailsResponse = getFeedDetails($feedId, $accessToken);

        if ($feedDetailsResponse['success']) {
          $feedDetails = $feedDetailsResponse['feedDetails'];

          $processingStatus = $feedDetails['processingStatus'];
          // Check the processingStatus
          if ($processingStatus === 'DONE') {
            echo "Feed processing is complete.\n";
            break;
          } elseif ($processingStatus === 'CANCELLED') {
            echo "The feed was cancelled before processing.\n";
            break;
          } elseif ($processingStatus === 'FATAL') {
            echo "The feed encountered a fatal error.\n";
            break;
          } else {
            echo "Feed processing is still in progress. Status: $processingStatus\n";
          }
        } else {
          echo 'Failed to retrieve feed details: ' . $feedDetailsResponse['error'];
          break;
        }
      } while (true); // Loop until a terminal state is reached
    } else {
      // Handle case where feedId is not available
      echo 'Feed ID not found.';
    }
  } else {
    echo 'Failed to upload feed document.';
  }


  curl_close($chUpload);
}

curl_close($ch);

// Function to retrieve feed details
function getFeedDetails($feedId, $accessToken)
{

  $url = "https://sellingpartnerapi-na.amazon.com/feeds/2021-06-30/feeds/$feedId";


  $headers = [
    'x-amz-access-token: ' . $accessToken,
    'Content-Type: application/json',
  ];

  $ch = curl_init();


  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);


  $response = curl_exec($ch);

  // Check for errors
  if (curl_errno($ch)) {
    return ['success' => false, 'error' => curl_error($ch)];
  } else {
    // Parse the response
    $responseData = json_decode($response, true);

    // Close cURL handle
    curl_close($ch);

    // Check if response is successful
    if (isset($responseData['errors'])) {
      return ['success' => false, 'error' => $responseData['errors']];
    } else {
      return ['success' => true, 'feedDetails' => $responseData];
    }
  }
}
