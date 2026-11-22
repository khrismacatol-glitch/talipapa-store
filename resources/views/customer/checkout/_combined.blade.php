@php
    use App\Enums\EWalletProvider;
    use App\Enums\PaymentMethod;
    use App\Support\PhilippinePhone;

    $selectedPayment = old('payment_method', $checkout['payment_method'] ?? PaymentMethod::Cod->value);
    if ($selectedPayment === 'gcash') {
        $selectedPayment = PaymentMethod::EWallet->value;
    }
    $selectedEwallet = old('ewallet_provider', $checkout['ewallet_provider'] ?? EWalletProvider::Gcash->value);
    $selectedAddressId = old('address_id', $checkout['address_id'] ?? $addresses->firstWhere('is_default', true)?->id);
    $storeGroups = $cart->items->groupBy(fn ($item) => $item->product?->store_profile_id);
    $subtotal = $cart->subtotal();
    $total = $subtotal + $deliveryFee;
    $phoneValue = old('phone', $checkout['customer']['phone'] ?? auth()->user()->phone);
@endphp

<form
    id="checkout-payment-form"
    method="POST"
    action="{{ route('customer.checkout.process') }}"
    class="space-y-4"
>
    @csrf
    <input type="hidden" name="step" value="0">

    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-3 sm:px-5">
            <i class="ri-shopping-cart-2-line text-lg text-brand-600" aria-hidden="true"></i>
            <h2 class="text-lg font-bold text-gray-900">Cart</h2>
        </div>
        <div class="divide-y divide-gray-100">
            @foreach ($storeGroups as $items)
                @php $store = $items->first()?->product?->store; @endphp
                <div>
                    <p class="flex items-center gap-2 px-4 pt-3 text-base font-semibold text-gray-800 sm:px-5">
                        <i class="ri-store-2-line text-brand-600" aria-hidden="true"></i>
                        {{ $store?->store_name ?? 'Store' }}
                    </p>
                    @foreach ($items as $item)
                        <div class="flex gap-3 px-4 py-3 sm:px-5">
                            <x-product-image
                                :product="$item->product"
                                class="h-16 w-16 shrink-0 rounded-lg bg-gray-100 object-cover ring-1 ring-gray-100"
                            />
                            <div class="min-w-0 flex-1">
                                <p class="line-clamp-2 text-base font-medium text-gray-900">{{ $item->product->name }}</p>
                                <p class="mt-1 text-base text-gray-500">Qty {{ $item->quantity }}</p>
                            </div>
                            <p class="shrink-0 text-base font-semibold text-gray-900">₱{{ number_format($item->total(), 2) }}</p>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
        <dl class="space-y-2 border-t border-gray-100 px-4 py-3 text-base sm:px-5">
            <div class="flex justify-between text-gray-600">
                <dt>Subtotal</dt>
                <dd class="font-medium text-gray-900">₱{{ number_format($subtotal, 2) }}</dd>
            </div>
            <div class="flex justify-between text-gray-600">
                <dt>
                    Delivery fee
                    @if (($storeCount ?? 1) > 1)
                        <span class="font-normal text-gray-500">(₱{{ number_format($perStoreFee ?? 50, 2) }} × {{ $storeCount }} stores)</span>
                    @endif
                </dt>
                <dd class="font-medium text-gray-900">₱{{ number_format($deliveryFee, 2) }}</dd>
            </div>
            <div class="flex justify-between border-t border-gray-100 pt-2 font-bold text-gray-900">
                <dt>Total</dt>
                <dd class="text-brand-600">₱{{ number_format($total, 2) }}</dd>
            </div>
        </dl>
    </section>

    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-3 sm:px-5">
            <i class="ri-user-3-line text-lg text-brand-600" aria-hidden="true"></i>
            <h2 class="text-lg font-bold text-gray-900">Info</h2>
        </div>
        <div class="space-y-4 px-4 py-4 sm:px-5">
            <div>
                <label for="checkout_name" class="mb-1.5 block text-base font-medium text-gray-700">Full Name</label>
                <input
                    id="checkout_name"
                    type="text"
                    name="name"
                    value="{{ old('name', $checkout['customer']['name'] ?? auth()->user()->name) }}"
                    required
                    autocomplete="name"
                    class="{{ $inputClass }}"
                >
                <x-input-error for="name" class="mt-1" />
            </div>
            <div>
                <label for="checkout_phone" class="mb-1.5 block text-base font-medium text-gray-700">Phone Number</label>
                <div class="flex overflow-hidden rounded-lg border border-gray-300 focus-within:border-brand-600 focus-within:ring-1 focus-within:ring-brand-600">
                    <span class="inline-flex shrink-0 items-center border-r border-gray-300 bg-gray-50 px-3.5 text-base font-medium text-gray-600">
                        {{ PhilippinePhone::PREFIX }}
                    </span>
                    <input
                        id="checkout_phone"
                        type="tel"
                        name="phone"
                        value="{{ PhilippinePhone::localPart($phoneValue) ?: $phoneValue }}"
                        required
                        autocomplete="tel"
                        placeholder="9XX XXX XXXX"
                        class="block w-full min-w-0 border-0 px-3 py-2.5 text-base placeholder:text-gray-400 focus:ring-0"
                    >
                </div>
                <x-input-error for="phone" class="mt-1" />
            </div>
        </div>
    </section>

    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 sm:px-5">
            <div class="flex items-center gap-2">
                <i class="ri-map-pin-2-line text-lg text-brand-600" aria-hidden="true"></i>
                <h2 class="text-lg font-bold text-gray-900">Address</h2>
            </div>
            <a
                href="{{ route('customer.addresses.create', ['from' => 'checkout']) }}"
                class="text-base font-medium text-brand-600 hover:text-brand-700"
            >
                Add new
            </a>
        </div>
        <div class="space-y-3 px-4 py-4 sm:px-5">
            @if ($addresses->isEmpty())
                <p class="text-base text-gray-600">Add a delivery address to place this order.</p>
            @else
                @foreach ($addresses as $address)
                    @php $isSelected = $selectedAddressId == $address->id; @endphp
                    <label @class([
                        'flex cursor-pointer items-start gap-3 rounded-xl border-2 p-4',
                        'border-brand-600 bg-brand-50/30' => $isSelected,
                        'border-gray-200 hover:border-brand-300' => ! $isSelected,
                    ])>
                        <input type="radio" name="address_id" value="{{ $address->id }}" @checked($isSelected) class="mt-1 text-brand-600" required>
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-gray-900">{{ $address->label ?? 'Address' }}</span>
                                @if ($address->is_default)
                                    <span class="rounded-full bg-brand-100 px-2 py-0.5 text-xs font-bold uppercase tracking-wide text-brand-700">Default</span>
                                @endif
                            </span>
                            <span class="mt-1 block text-base text-gray-600">{{ $address->fullAddress() }}</span>
                        </span>
                        <a
                            href="{{ route('customer.addresses.edit', ['address' => $address, 'from' => 'checkout']) }}"
                            class="text-base font-medium text-brand-600 hover:text-brand-700"
                            onclick="event.stopPropagation()"
                        >
                            Edit
                        </a>
                    </label>
                @endforeach
            @endif
            <x-input-error for="address_id" class="mt-1" />
            <div>
                <label for="checkout_notes" class="mb-1.5 block text-base font-medium text-gray-700">Delivery instructions (optional)</label>
                <textarea id="checkout_notes" name="notes" rows="2" class="{{ $inputClass }}">{{ old('notes', $checkout['notes'] ?? '') }}</textarea>
            </div>
        </div>
    </section>

    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-3 sm:px-5">
            <i class="ri-bank-card-line text-lg text-brand-600" aria-hidden="true"></i>
            <h2 class="text-lg font-bold text-gray-900">Payment</h2>
        </div>
        <div class="space-y-3 px-4 py-4 sm:px-5">
            @foreach (PaymentMethod::forOnlineCheckout() as $method)
                <label @class([
                    'flex cursor-pointer items-start gap-3 rounded-xl border-2 p-4',
                    'border-brand-600 bg-brand-50/30' => $selectedPayment === $method->value,
                    'border-gray-200' => $selectedPayment !== $method->value,
                ])>
                    <input
                        type="radio"
                        name="payment_method"
                        value="{{ $method->value }}"
                        @checked($selectedPayment === $method->value)
                        class="payment-method-radio mt-1 text-brand-600"
                    >
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center justify-between gap-2">
                            <span class="font-semibold text-gray-900">{{ $method === PaymentMethod::Cod ? 'Cash on Delivery' : $method->label() }}</span>
                            @if ($selectedPayment === $method->value)
                                <i class="ri-checkbox-circle-fill text-xl text-brand-600" aria-hidden="true"></i>
                            @endif
                        </span>
                        <span class="mt-0.5 block text-base text-gray-500">{{ $method->description() }}</span>
                    </span>
                </label>
                @if ($method === PaymentMethod::EWallet)
                    <div id="ewallet-panel" class="{{ $selectedPayment === PaymentMethod::EWallet->value ? '' : 'hidden' }} px-1">
                        <x-payment.ewallet-providers :selected="$selectedEwallet" />
                    </div>
                @elseif ($method === PaymentMethod::Card)
                    <div id="card-panel" class="{{ $selectedPayment === PaymentMethod::Card->value ? '' : 'hidden' }} space-y-3 px-1">
                        <input type="text" name="card_holder_name" value="{{ old('card_holder_name', $checkout['payment']['holder'] ?? '') }}" placeholder="Name on card" class="{{ $inputClass }} card-field">
                        <input type="text" name="card_number" value="{{ old('card_number') }}" placeholder="Card number" class="{{ $inputClass }} card-field">
                        <div class="grid grid-cols-2 gap-3">
                            <input type="text" name="card_expiry" value="{{ old('card_expiry', $checkout['payment']['expiry'] ?? '') }}" placeholder="MM/YY" class="{{ $inputClass }} card-field">
                            <input type="password" name="card_cvv" placeholder="CVV" class="{{ $inputClass }} card-field">
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </section>

    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-3 sm:px-5">
            <i class="ri-checkbox-circle-line text-lg text-brand-600" aria-hidden="true"></i>
            <h2 class="text-lg font-bold text-gray-900">Confirm</h2>
        </div>
        <div class="space-y-4 px-4 py-4 sm:px-5">
            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-4 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50/30">
                <input type="checkbox" name="confirm" value="1" required class="mt-0.5 rounded text-brand-600">
                <span>
                    <span class="block text-base font-semibold text-gray-900">I confirm this order is correct</span>
                    <span class="mt-0.5 block text-base text-gray-500">By placing this order, you agree to our delivery and payment terms.</span>
                </span>
            </label>
            <button
                type="submit"
                @disabled($addresses->isEmpty())
                @class([
                    'inline-flex w-full items-center justify-center rounded-xl px-6 py-3 text-base font-semibold text-white shadow-sm transition sm:w-auto',
                    'bg-brand-600 hover:bg-brand-700' => $addresses->isNotEmpty(),
                    'cursor-not-allowed bg-gray-300' => $addresses->isEmpty(),
                ])
            >
                Place Order
            </button>
        </div>
    </section>
</form>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('checkout-payment-form');
        if (! form) return;
        const ewalletPanel = document.getElementById('ewallet-panel');
        const cardPanel = document.getElementById('card-panel');
        const cardFields = form.querySelectorAll('.card-field');
        const sync = () => {
            const selected = form.querySelector('.payment-method-radio:checked')?.value;
            ewalletPanel?.classList.toggle('hidden', selected !== 'ewallet');
            cardPanel?.classList.toggle('hidden', selected !== 'card');
            cardFields.forEach((field) => {
                field.required = selected === 'card';
                field.disabled = selected !== 'card';
            });
        };
        form.querySelectorAll('.payment-method-radio').forEach((radio) => radio.addEventListener('change', sync));
        sync();
    });
</script>
@endpush
