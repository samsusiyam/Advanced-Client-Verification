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
 * Render prominent verification banner in client area.
 */
function cv_render_client_banner(string $context = 'general'): string
{
    if (cv_setting('enabled', 'yes') !== 'yes') {
        return '';
    }
    $clientId = (int) (($_SESSION['uid'] ?? 0) ?: ($_SESSION['clientsdetails']['userid'] ?? 0));
    if (!$clientId) {
        return '';
    }
    if (cv_is_client_verified($clientId)) {
        return '';
    }
    // Do not show inside the module verification page itself
    if (isset($_GET['m']) && $_GET['m'] === 'clientverification') {
        return '';
    }

    $activeVerification = \ClientVerification\Services\VerificationService::getActiveForClient($clientId);
    $status = $activeVerification ? $activeVerification->status : 'unverified';

    if ($status === 'under_review') {
        $html = '<div id="cv-verification-banner-wrapper" style="width: 100%; max-width: 1140px; margin: 15px auto 10px auto; padding: 0 15px; box-sizing: border-box; clear: both; display: none;">
            <div style="background: #fefce8; border: 1px solid #fef08a; border-radius: 8px; padding: 14px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="color: #ca8a04; font-size: 20px; line-height: 1; flex-shrink: 0;">
                        <i class="fa fa-clock-o"></i>
                    </div>
                    <div>
                        <strong style="font-size: 15px; color: #854d0e; display: block; margin-bottom: 2px;">Account Verification Under Review</strong>
                        <span style="color: #713f12; font-size: 13px;">Your identity documents have been submitted and are being reviewed by compliance.</span>
                    </div>
                </div>
                <div>
                    <a href="index.php?m=clientverification" class="btn btn-warning btn-sm" style="font-weight: 600; font-size: 13px; padding: 6px 16px; border-radius: 4px;">
                        <i class="fa fa-eye"></i> View Status
                    </a>
                </div>
            </div>
        </div>';
    } else {
        $msg = 'To fully use our services, verify your identity.';
        if ($context === 'cart') {
            $msg = 'Identity verification is required before checkout for items in your cart.';
        }

        $html = '<div id="cv-verification-banner-wrapper" style="width: 100%; max-width: 1140px; margin: 15px auto 10px auto; padding: 0 15px; box-sizing: border-box; clear: both; display: none;">
            <div style="background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; padding: 14px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="color: #d97706; font-size: 20px; line-height: 1; flex-shrink: 0;">
                        <i class="fa fa-exclamation-triangle"></i>
                    </div>
                    <div>
                        <strong style="font-size: 15px; color: #c2410c; display: block; margin-bottom: 2px;">Account Verification Required</strong>
                        <span style="color: #9a3412; font-size: 13px;">' . htmlspecialchars($msg) . '</span>
                    </div>
                </div>
                <div>
                    <a href="index.php?m=clientverification" class="btn" style="background: #c2410c; color: #ffffff; font-weight: 600; font-size: 13px; padding: 7px 18px; border-radius: 4px; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; border: none; box-shadow: 0 2px 4px rgba(194, 65, 12, 0.2);">
                        <i class="fa fa-user"></i> Verify Now
                    </a>
                </div>
            </div>
        </div>';
    }

    $js = '<script>
    (function() {
        function placeCvBanner() {
            var bannerWrapper = document.getElementById("cv-verification-banner-wrapper");
            if (!bannerWrapper) return;
            
            var container = document.querySelector("#main-body > .container")
                || document.querySelector(".main-content > .container")
                || document.querySelector(".app-main > .container")
                || document.querySelector(".content-area > .container")
                || document.querySelector(".primary-content")
                || document.querySelector("#main-body")
                || document.querySelector(".main-content")
                || document.querySelector(".site-main");

            if (container) {
                if (bannerWrapper.parentNode !== container) {
                    container.insertBefore(bannerWrapper, container.firstChild);
                }
                bannerWrapper.style.display = "block";
            }
        }
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", placeCvBanner);
        } else {
            placeCvBanner();
        }
        window.addEventListener("load", placeCvBanner);
    })();
    </script>';

    return $html . $js;
}

/**
 * Server-side checkout guard. Blocks checkout when any cart product requires
 * KYC and the client is not verified. This is enforced on the server, not JS.
 */
function clientverification_checkout_guard($vars)
{
    if (cv_setting('enabled', 'yes') !== 'yes') {
        return [];
    }

    $clientId = (int) (($_SESSION['uid'] ?? 0) ?: ($_SESSION['clientsdetails']['userid'] ?? 0));
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
        $msg = 'Identity verification is required before checkout' . (count($requiredProductNames) ? ' for: <strong>' . $names . '</strong>' : '') . '.';
        $btn = ' <a href="' . $link . '" class="btn btn-warning btn-xs" style="margin-left: 10px; background: #c2410c; border: none; color: #ffffff; font-weight: 700; padding: 4px 12px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; text-decoration: none;"><i class="fa fa-shield"></i> Verify Now</a>';
        return [$msg . $btn];
    }

    return [];
}

/**
 * Client-area cart notice (visual block in addition to server guard).
 */
function clientverification_cart_notice($vars)
{
    $clientId = (int) (($_SESSION['uid'] ?? 0) ?: ($_SESSION['clientsdetails']['userid'] ?? 0));
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

add_hook('ClientAreaHeaderOutput', 1, function ($vars) {
    return cv_render_client_banner('general');
});

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
    $clientId = (int) (($_SESSION['uid'] ?? 0) ?: ($_SESSION['clientsdetails']['userid'] ?? 0));
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
 * Run scheduled verification tasks via WHMCS native daily cron.
 */
function clientverification_daily_cron($vars)
{
    if (file_exists(__DIR__ . '/cron.php')) {
        require_once __DIR__ . '/cron.php';
    }
    if (function_exists('cv_run_cron')) {
        cv_run_cron();
    }
    return $vars;
}
