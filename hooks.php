<?php

/**
 * WHMCS hooks for Advanced Client Verification.
 * Registers the server-side Checkout Guard and client-area notices.
 */

use Illuminate\Database\Capsule\Manager as Capsule;
use ClientVerification\Security\Sanitizer;

require_once __DIR__ . '/app/Helpers/functions.php';

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Server-side checkout guard. Blocks checkout when any cart product requires
 * KYC and the client is not verified. This is enforced on the server, not JS.
 */
function clientverification_checkout_guard($vars)
{
    $clientId = (int) ($_SESSION['uid'] ?? 0);
    if (!$clientId) {
        return [];
    }

    if (cv_is_client_verified($clientId)) {
        return [];
    }

    $cart = $_SESSION['cart'] ?? [];
    $products = $cart['products'] ?? [];

    $requiredProductNames = [];
    $blocked = false;

    foreach ($products as $item) {
        $pid = is_array($item) ? ((int) ($item['pid'] ?? 0)) : (int) $item;
        if (!$pid) {
            continue;
        }
        if (cv_kyc_required_for_product($clientId, $pid)) {
            $blocked = true;
            $name = Capsule::table('tblproducts')->where('id', $pid)->value('name');
            if ($name) {
                $requiredProductNames[] = $name;
            }
        }
    }

    if ($blocked) {
        $link = 'index.php?m=clientverification';
        $names = implode(', ', array_map([Sanitizer::class, 'escape'], $requiredProductNames));
        $message = 'Identity verification is required before checkout. '
            . 'Please complete verification'
            . (count($requiredProductNames) ? ' for: ' . $names : '')
            . '. <a href="' . $link . '">Verify Now</a>';
        return [$message];
    }

    return [];
}

/**
 * Client-area cart notice (visual block in addition to server guard).
 */
function clientverification_cart_notice($vars)
{
    $clientId = (int) ($_SESSION['uid'] ?? 0);
    if (!$clientId) {
        return $vars;
    }

    $cart = $_SESSION['cart'] ?? [];
    $products = $cart['products'] ?? [];
    $blocked = false;
    foreach ($products as $item) {
        $pid = is_array($item) ? ((int) ($item['pid'] ?? 0)) : (int) $item;
        if ($pid && cv_kyc_required_for_product($clientId, $pid)) {
            $blocked = true;
            break;
        }
    }

    if ($blocked && !cv_is_client_verified($clientId)) {
        $vars['cvKycBlocked'] = true;
        $vars['cvKycMessage'] = 'Identity verification is required for items in your cart.';
    }

    return $vars;
}

add_hook('ShoppingCartValidateCheckout', 1, 'clientverification_checkout_guard');
add_hook('ClientAreaPageCart', 1, 'clientverification_cart_notice');
add_hook('DailyCronJob', 1, 'clientverification_daily_cron');

/**
 * Add Identity Verification menu item to the Client Area primary navbar.
 */
add_hook('ClientAreaPrimaryNavbar', 1, function ($primaryNavbar) {
    if (cv_setting('enabled', 'yes') !== 'yes') {
        return;
    }
    $clientId = (int) ($_SESSION['uid'] ?? 0);
    if (!$clientId) {
        return;
    }
    if (!is_null($primaryNavbar->getChild('Account'))) {
        $primaryNavbar->getChild('Account')->addChild('ClientVerification', [
            'label' => 'Identity Verification',
            'uri' => 'index.php?m=clientverification',
            'order' => 50,
        ]);
    }
});

/**
 * Run scheduled verification tasks via WHMCS cron.
 */
function clientverification_daily_cron($vars)
{
    if (function_exists('cv_run_cron')) {
        cv_run_cron();
    }
    return $vars;
}
