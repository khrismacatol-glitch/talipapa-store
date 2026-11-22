<?php

namespace App\Http\Controllers\Customer;

use App\Enums\EWalletProvider;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Concerns\ResolvesCart;
use App\Http\Requests\Checkout\ProcessCheckoutRequest;
use App\Models\Address;
use App\Services\CartService;
use App\Services\OrderService;
use App\Services\Search\SearchFeedbackService;
use App\Support\CheckoutPaymentSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    use ResolvesCart;

    public function __construct(
        private readonly CartService $cartService,
        private readonly OrderService $orderService,
        private readonly SearchFeedbackService $searchFeedback,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $requestedStep = $request->route('step') ?? $request->query('step');
        $step = $requestedStep === null || $requestedStep === ''
            ? 0
            : max(0, min(5, (int) $requestedStep));
        if ($step > 0) {
            $step = 0;
        }
        $cart = $this->resolveCart()->load('items.product.store');

        if ($cart->items->isEmpty()) {
            return redirect()->route('cart.index')->with('error', 'Your cart is empty.');
        }

        $checkout = $request->session()->get('checkout', []);
        if (($checkout['payment_method'] ?? null) === 'gcash' || empty($checkout['payment_method'])) {
            $checkout['payment_method'] = PaymentMethod::Cod->value;
        }
        $addresses = $request->user()->addresses()->with(['region', 'province', 'city', 'barangay'])->get();
        if (empty($checkout['address_id']) && $addresses->isNotEmpty()) {
            $checkout['address_id'] = $addresses->firstWhere('is_default', true)?->id ?? $addresses->first()->id;
        }
        if (empty($checkout['customer'])) {
            $checkout['customer'] = [
                'name' => $request->user()->name,
                'phone' => $request->user()->phone,
            ];
        }
        $request->session()->put('checkout', $checkout);
        $storeCount = max(1, $cart->storeCount());
        $perStoreFee = $this->orderService->perStoreDeliveryFee();
        $deliveryFee = $this->orderService->calculateDeliveryFee($storeCount);

        return view('customer.checkout', compact(
            'step',
            'cart',
            'checkout',
            'addresses',
            'deliveryFee',
            'storeCount',
            'perStoreFee',
        ));
    }

    public function selectAddress(Request $request): RedirectResponse
    {
        $address = Address::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($request->integer('address_id'));

        $checkout = $request->session()->get('checkout', []);
        $checkout['address_id'] = $address->id;
        $request->session()->put('checkout', $checkout);

        return redirect()->route('customer.checkout.show', ['step' => 0]);
    }

    public function selectPayment(Request $request): RedirectResponse
    {
        $paymentMethod = (string) $request->input('payment_method', PaymentMethod::Cod->value);
        $request->validate(array_merge(
            [
                'payment_method' => [
                    'required',
                    Rule::in(array_map(
                        fn (PaymentMethod $method) => $method->value,
                        PaymentMethod::forOnlineCheckout(),
                    )),
                ],
            ],
            match ($paymentMethod) {
                PaymentMethod::EWallet->value => [
                    'ewallet_provider' => ['required', new Enum(EWalletProvider::class)],
                ],
                PaymentMethod::Card->value => [
                    'card_holder_name' => ['required', 'string', 'max:255'],
                    'card_number' => ['required', 'digits_between:13,19'],
                    'card_expiry' => ['required', 'string', 'regex:/^(0[1-9]|1[0-2])\/\d{2}$/'],
                    'card_cvv' => ['required', 'string', 'regex:/^\d{3,4}$/'],
                ],
                default => [],
            },
        ));

        $checkout = $request->session()->get('checkout', []);
        $checkout['payment_method'] = $paymentMethod;
        unset($checkout['ewallet_provider'], $checkout['payment']);

        if ($paymentMethod === PaymentMethod::EWallet->value) {
            $checkout['ewallet_provider'] = $request->input('ewallet_provider');
        } elseif ($paymentMethod === PaymentMethod::Card->value) {
            $cardNumber = preg_replace('/\D/', '', (string) $request->input('card_number'));
            $checkout['payment'] = [
                'holder' => $request->input('card_holder_name'),
                'last_four' => substr((string) $cardNumber, -4),
                'expiry' => $request->input('card_expiry'),
            ];
        }

        $request->session()->put('checkout', $checkout);

        return redirect()->route('customer.checkout.show', ['step' => 0]);
    }

    public function process(ProcessCheckoutRequest $request): RedirectResponse
    {
        $step = (int) $request->input('step', 1);
        $checkout = $request->session()->get('checkout', []);

        if ($step === 0) {
            $checkout['customer'] = $request->only(['name', 'phone']);
            $request->user()->update([
                'name' => $request->input('name'),
                'phone' => $request->input('phone'),
            ]);
            $checkout['address_id'] = $request->integer('address_id');
            if ($request->filled('notes')) {
                $checkout['notes'] = $request->string('notes')->toString();
            }
            $paymentMethod = $request->input('payment_method');
            $checkout['payment_method'] = $paymentMethod;
            unset($checkout['ewallet_provider'], $checkout['payment']);
            if ($paymentMethod === PaymentMethod::EWallet->value) {
                $checkout['ewallet_provider'] = $request->input('ewallet_provider');
            } elseif ($paymentMethod === PaymentMethod::Card->value) {
                $cardNumber = preg_replace('/\D/', '', (string) $request->input('card_number'));
                $checkout['payment'] = [
                    'holder' => $request->input('card_holder_name'),
                    'last_four' => substr((string) $cardNumber, -4),
                    'expiry' => $request->input('card_expiry'),
                ];
            }
            $request->session()->put('checkout', $checkout);

            return $this->placeOrder($request, $checkout);
        }

        if ($step === 1) {
            return redirect()->route('customer.checkout.show', ['step' => 2]);
        }

        if ($step === 2) {
            $checkout['customer'] = $request->only(['name', 'phone']);
            $request->user()->update([
                'name' => $request->input('name'),
                'phone' => $request->input('phone'),
            ]);
            $request->session()->put('checkout', $checkout);

            return redirect()->route('customer.checkout.show', ['step' => 3]);
        }

        if ($step === 3) {
            $address = Address::query()
                ->where('user_id', $request->user()->id)
                ->findOrFail($request->integer('address_id'));

            $checkout['address_id'] = $address->id;

            if ($request->filled('notes')) {
                $checkout['notes'] = $request->string('notes')->toString();
            }

            $request->session()->put('checkout', $checkout);

            return redirect()->route('customer.checkout.show', ['step' => 4]);
        }

        if ($step === 4) {
            $paymentMethod = $request->input('payment_method');
            $checkout['payment_method'] = $paymentMethod;
            unset($checkout['ewallet_provider'], $checkout['payment']);

            if ($paymentMethod === PaymentMethod::EWallet->value) {
                $checkout['ewallet_provider'] = $request->input('ewallet_provider');
            } elseif ($paymentMethod === PaymentMethod::Card->value) {
                $cardNumber = preg_replace('/\D/', '', (string) $request->input('card_number'));
                $checkout['payment'] = [
                    'holder' => $request->input('card_holder_name'),
                    'last_four' => substr($cardNumber, -4),
                    'expiry' => $request->input('card_expiry'),
                ];
            }

            $request->session()->put('checkout', $checkout);

            return redirect()->route('customer.checkout.show', ['step' => 5]);
        }

        return $this->placeOrder($request, $checkout);
    }

    private function placeOrder(Request $request, array $checkout): RedirectResponse
    {
        $cart = $this->resolveCart()->load('items.product');
        $address = Address::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($checkout['address_id'] ?? 0);

        $paymentNote = CheckoutPaymentSummary::orderNote($checkout);
        $orderNotes = collect([$checkout['notes'] ?? null, $paymentNote])->filter()->implode("\n");

        $orders = $this->orderService->createFromCart(
            $cart,
            $request->user(),
            $address,
            PaymentMethod::tryFromStored($checkout['payment_method'] ?? PaymentMethod::Cod->value) ?? PaymentMethod::Cod,
            $orderNotes ?: null,
        );

        foreach ($orders as $order) {
            \App\Events\OrderStatusChanged::dispatch($order, \App\Enums\OrderStatus::Pending, \App\Enums\OrderStatus::Pending);
            $this->searchFeedback->recordPurchases($request, $order);
        }

        $request->session()->forget('checkout');

        if ($orders->count() === 1) {
            return redirect()
                ->route('customer.orders.show', $orders->first())
                ->with('success', 'Order placed successfully!');
        }

        return redirect()
            ->route('customer.orders.index')
            ->with('success', $orders->count().' orders placed (₱'.number_format($this->orderService->perStoreDeliveryFee(), 2).' delivery per store).');
    }
}
