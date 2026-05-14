<?php

return [
    'etilize' => [
        'cdn_url' => env( 'ETILIZE_CDN_URL', 'https://content.etilize.com' ),
        'sample_mode' => env( 'ETILIZE_SAMPLE_MODE', false ),
        'thumbnail_source' => env( 'PRODUCT_THUMBNAIL_SOURCE', 'td_synnex' ),
    ],

    'tdsynnex' => [
        'environment' => env( 'TDSYNNEX_ENV', 'test' ),
        'auto_submit_po' => env( 'TDSYNNEX_AUTO_SUBMIT_PO', false ),
        'account' => env( 'TDSYNNEX_ACCOUNT' ),
        'email' => env( 'TDSYNNEX_EMAIL' ),
        'username' => env( 'TDSYNNEX_CUSTOMER_NAME', 'Molagroup' ),
        'customer_name' => env( 'TDSYNNEX_CUSTOMER_NAME', 'Molagroup' ),
        'password' => env( 'TDSYNNEX_PASSWORD' ),

        'test' => [
            'base_url' => env( 'TDSYNNEX_TEST_BASE_URL', 'https://testec.us.tdsynnex.com' ),
            'po_url' => env( 'TDSYNNEX_TEST_PO_URL', 'https://testec.synnex.com/SynnexXML/PO' ),
            'invoice_query_url' => env( 'TDSYNNEX_TEST_INVOICE_QUERY_URL', 'https://testws.us.tdsynnex.com/webservice/invoice/query' ),
        ],

        'prod' => [
            'base_url' => env( 'TDSYNNEX_PROD_BASE_URL', 'https://ec.us.tdsynnex.com' ),
            'po_url' => env( 'TDSYNNEX_PROD_PO_URL', 'https://ec.synnex.com/SynnexXML/PO' ),
            'invoice_query_url' => env( 'TDSYNNEX_PROD_INVOICE_QUERY_URL', 'https://ws.us.tdsynnex.com/webservice/invoice/query' ),
        ],
    ],

    'ziptax' => [
        'api_key' => env( 'ZIPTAX_API_KEY' ),
        'version' => env( 'ZIPTAX_VERSION', 'v50' ),
        'endpoint' => env( 'ZIPTAX_ENDPOINT', 'https://api.zip-tax.com' ),
        'ttl' => env( 'ZIPTAX_TTL', 60 ),
    ],
];
