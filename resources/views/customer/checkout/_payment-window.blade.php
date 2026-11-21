@php
    use App\Enums\EWalletProvider;
    use App\Enums\PaymentMethod;

    $selectedPayment = old('payment_method', $checkout['payment_method'] ?? PaymentMethod::Cod->value);
    if ($selectedPayment === 'gcash') {
        $selectedPayment = PaymentMethod::EWallet->value;
    }
    $selectedEwallet = old('ewallet_provider', $checkout['ewallet_provider'] ?? EWalletProvider::Gcash->value);
@endphp

<div class="space-y-4">
    <a
        href="{{ route('customer.checkout.show', ['step' => 0]) }}"
        class="inline-flex items-center gap-1 text-base font-medium text-gray-500 hover:text-brand-600"
    >
        <i class="ri-arrow-left-s-line" aria-hidden="true"></i>
        Back to checkout
    </a>

    <h2 class="text-lg font-bold text-gray-900">Payment Methods</h2>

    <form
        id="checkout-payment-form"
        method="POST"
        action="{{ route('customer.checkout.select-payment') }}"
        class="space-y-3"
    >
        @csrf

        @foreach (PaymentMethod::forOnlineCheckout() as $method)
            <label @class([
                'flex cursor-pointer items-start gap-3 rounded-2xl border-2 bg-white p-4 shadow-sm',
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

        <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-brand-600 px-6 py-3 text-base font-semibold text-white hover:bg-brand-700 sm:w-auto">
            Use this payment method
        </button>
    </form>
</div>

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
