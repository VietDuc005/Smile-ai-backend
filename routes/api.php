<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\AlertController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\InventoryController as AdminInventoryController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\PostController as AdminPostController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\TagController as AdminTagController;
use App\Http\Controllers\Api\Admin\VoucherController as AdminVoucherController;
use App\Http\Controllers\Api\Client\VoucherController as ClientVoucherController;
use App\Http\Controllers\Api\Admin\TicketController as AdminTicketController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Client\InventoryController as ClientInventoryController;
use App\Http\Controllers\Api\Client\InvoiceController as ClientInvoiceController;
use App\Http\Controllers\Api\Client\NotificationController as ClientNotificationController;
use App\Http\Controllers\Api\Client\OrderController as ClientOrderController;
use App\Http\Controllers\Api\Client\PostController as ClientPostController;
use App\Http\Controllers\Api\Client\ProductController as ClientProductController;
use App\Http\Controllers\Api\Client\TicketController as ClientTicketController;
use App\Http\Controllers\Api\Client\UserController as ClientUserController;
use App\Http\Controllers\Api\PortalController;
use App\Http\Controllers\Api\Webhook\PaymentController;
use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\AuthorizeRole;
use App\Http\Middleware\CheckAdminRole;

return [
    'middleware_aliases' => [
        'auth.jwt' => AuthenticateJwt::class,
        'role.admin' => CheckAdminRole::class,
        'role.user' => [
            'class' => AuthorizeRole::class,
            'roles' => ['user', 'admin'],
        ],
    ],
    'auth' => [
        ['method' => 'POST', 'uri' => '/auth/request-otp', 'action' => [AuthController::class, 'requestOtp'], 'middleware' => [], 'access' => 'guest'],
        ['method' => 'POST', 'uri' => '/auth/verify-otp', 'action' => [AuthController::class, 'verifyOtp'], 'middleware' => [], 'access' => 'guest'],
        ['method' => 'POST', 'uri' => '/auth/google', 'action' => [AuthController::class, 'loginWithGoogle'], 'middleware' => [], 'access' => 'guest'],
        ['method' => 'GET', 'uri' => '/auth/me', 'action' => [AuthController::class, 'me'], 'middleware' => ['auth.jwt'], 'access' => 'authenticated'],
    ],
    'admin' => [
        ['method' => 'GET', 'uri' => '/admin/dashboard', 'action' => [DashboardController::class, 'summary'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'GET', 'uri' => '/admin/products', 'action' => [AdminProductController::class, 'index'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'POST', 'uri' => '/admin/products', 'action' => [AdminProductController::class, 'store'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'PUT', 'uri' => '/admin/products/{id}', 'action' => [AdminProductController::class, 'update'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'DELETE', 'uri' => '/admin/products/{id}', 'action' => [AdminProductController::class, 'destroy'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'GET', 'uri' => '/admin/inventory/{productId}', 'action' => [AdminInventoryController::class, 'index'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'POST', 'uri' => '/admin/inventory/import', 'action' => [AdminInventoryController::class, 'import'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'PATCH', 'uri' => '/admin/inventory/{accountId}', 'action' => [AdminInventoryController::class, 'updateAccount'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'PATCH', 'uri' => '/admin/inventory/{accountId}/status', 'action' => [AdminInventoryController::class, 'updateStatus'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'POST', 'uri' => '/admin/inventory/{accountId}/release', 'action' => [AdminInventoryController::class, 'release'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'PATCH', 'uri' => '/admin/inventory/{accountId}/totp-secret', 'action' => [AdminInventoryController::class, 'setTotpSecret'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'GET', 'uri' => '/admin/orders', 'action' => [AdminOrderController::class, 'index'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'GET', 'uri' => '/admin/orders/{id}', 'action' => [AdminOrderController::class, 'show'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'POST', 'uri' => '/admin/orders/{id}/match', 'action' => [AdminOrderController::class, 'match'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'POST', 'uri' => '/admin/orders/{id}/cancel', 'action' => [AdminOrderController::class, 'cancel'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'POST', 'uri' => '/admin/orders/{id}/items/{itemId}/provision', 'action' => [AdminOrderController::class, 'provision'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'GET', 'uri' => '/admin/tickets', 'action' => [AdminTicketController::class, 'index'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'GET', 'uri' => '/admin/tickets/{id}', 'action' => [AdminTicketController::class, 'show'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'POST', 'uri' => '/admin/tickets/{id}/reply', 'action' => [AdminTicketController::class, 'reply'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'PUT', 'uri' => '/admin/tickets/{id}/status', 'action' => [AdminTicketController::class, 'updateStatus'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'POST', 'uri' => '/admin/tickets/{id}/close', 'action' => [AdminTicketController::class, 'close'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'POST', 'uri' => '/admin/tickets/{id}/warranty', 'action' => [AdminTicketController::class, 'warranty'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'GET', 'uri' => '/admin/users', 'action' => [AdminUserController::class, 'index'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'POST', 'uri' => '/admin/users/{id}/block', 'action' => [AdminUserController::class, 'block'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'POST', 'uri' => '/admin/users/{id}/unblock', 'action' => [AdminUserController::class, 'unblock'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'DELETE', 'uri' => '/admin/users/{id}', 'action' => [AdminUserController::class, 'destroy'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'GET', 'uri' => '/admin/tags', 'action' => [AdminTagController::class, 'index'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'POST', 'uri' => '/admin/tags', 'action' => [AdminTagController::class, 'store'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'PUT', 'uri' => '/admin/tags/{id}', 'action' => [AdminTagController::class, 'update'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'DELETE', 'uri' => '/admin/tags/{id}', 'action' => [AdminTagController::class, 'destroy'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'GET', 'uri' => '/admin/vouchers', 'action' => [AdminVoucherController::class, 'index'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'POST', 'uri' => '/admin/vouchers', 'action' => [AdminVoucherController::class, 'store'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'PUT', 'uri' => '/admin/vouchers/{id}', 'action' => [AdminVoucherController::class, 'update'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'DELETE', 'uri' => '/admin/vouchers/{id}', 'action' => [AdminVoucherController::class, 'destroy'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'GET', 'uri' => '/admin/alerts', 'action' => [AlertController::class, 'index'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'POST', 'uri' => '/admin/alerts/renewal-reminders', 'action' => [AlertController::class, 'sendRenewalReminders'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'POST', 'uri' => '/admin/alerts/expired-notifications', 'action' => [AlertController::class, 'sendExpiredNotifications'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'GET', 'uri' => '/admin/posts', 'action' => [AdminPostController::class, 'index'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'GET', 'uri' => '/admin/posts/{id}', 'action' => [AdminPostController::class, 'show'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'POST', 'uri' => '/admin/posts', 'action' => [AdminPostController::class, 'store'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'PUT', 'uri' => '/admin/posts/{id}', 'action' => [AdminPostController::class, 'update'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
        ['method' => 'DELETE', 'uri' => '/admin/posts/{id}', 'action' => [AdminPostController::class, 'destroy'], 'middleware' => ['auth.jwt', 'role.admin'], 'access' => 'admin'],
    ],
    'client' => [
        ['method' => 'POST', 'uri' => '/vouchers/validate', 'action' => [ClientVoucherController::class, 'validate'], 'middleware' => ['auth.jwt', 'role.user'], 'access' => 'user'],
        ['method' => 'GET', 'uri' => '/posts', 'action' => [ClientPostController::class, 'index'], 'middleware' => [], 'access' => 'guest'],
        ['method' => 'GET', 'uri' => '/posts/{slug}', 'action' => [ClientPostController::class, 'show'], 'middleware' => [], 'access' => 'guest'],
        ['method' => 'POST', 'uri' => '/posts/{slug}/view', 'action' => [ClientPostController::class, 'recordView'], 'middleware' => [], 'access' => 'guest'],
        ['method' => 'POST', 'uri' => '/posts/{slug}/like', 'action' => [ClientPostController::class, 'like'], 'middleware' => [], 'access' => 'guest'],
        ['method' => 'GET', 'uri' => '/products', 'action' => [ClientProductController::class, 'index'], 'middleware' => [], 'access' => 'guest'],
        ['method' => 'GET', 'uri' => '/products/{id}', 'action' => [ClientProductController::class, 'show'], 'middleware' => [], 'access' => 'guest'],
        ['method' => 'GET', 'uri' => '/products/{productId}/availability', 'action' => [ClientInventoryController::class, 'availability'], 'middleware' => [], 'access' => 'guest'],
        ['method' => 'POST', 'uri' => '/orders', 'action' => [ClientOrderController::class, 'store'], 'middleware' => ['auth.jwt', 'role.user'], 'access' => 'user'],
        ['method' => 'GET', 'uri' => '/orders', 'action' => [ClientOrderController::class, 'index'], 'middleware' => ['auth.jwt', 'role.user'], 'access' => 'user'],
        ['method' => 'GET', 'uri' => '/orders/{id}', 'action' => [ClientOrderController::class, 'show'], 'middleware' => ['auth.jwt', 'role.user'], 'access' => 'user'],
        ['method' => 'GET', 'uri' => '/orders/{id}/invoice', 'action' => [ClientInvoiceController::class, 'show'], 'middleware' => ['auth.jwt', 'role.user'], 'access' => 'user'],
        ['method' => 'POST', 'uri' => '/tickets', 'action' => [ClientTicketController::class, 'store'], 'middleware' => ['auth.jwt', 'role.user'], 'access' => 'user'],
        ['method' => 'GET', 'uri' => '/tickets', 'action' => [ClientTicketController::class, 'index'], 'middleware' => ['auth.jwt', 'role.user'], 'access' => 'user'],
        ['method' => 'GET', 'uri' => '/tickets/{id}', 'action' => [ClientTicketController::class, 'show'], 'middleware' => ['auth.jwt', 'role.user'], 'access' => 'user'],
        ['method' => 'GET', 'uri' => '/me/profile', 'action' => [ClientUserController::class, 'profile'], 'middleware' => ['auth.jwt', 'role.user'], 'access' => 'user'],
        ['method' => 'GET', 'uri' => '/notifications', 'action' => [ClientNotificationController::class, 'index'], 'middleware' => ['auth.jwt', 'role.user'], 'access' => 'user'],
        ['method' => 'POST', 'uri' => '/notifications/read-all', 'action' => [ClientNotificationController::class, 'markAllRead'], 'middleware' => ['auth.jwt', 'role.user'], 'access' => 'user'],
        ['method' => 'POST', 'uri' => '/notifications/{id}/read', 'action' => [ClientNotificationController::class, 'markRead'], 'middleware' => ['auth.jwt', 'role.user'], 'access' => 'user'],
    ],
    'portal' => [
        ['method' => 'POST', 'uri' => '/portal/totp', 'action' => [PortalController::class, 'getCode'], 'middleware' => [], 'access' => 'guest'],
    ],
    'webhook' => [
        ['method' => 'POST', 'uri' => '/webhooks/payment', 'action' => [PaymentController::class, 'receive'], 'middleware' => [], 'access' => 'system'],
    ],
];
