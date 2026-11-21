@php
    $selectedId = old('address_id', $checkout['address_id'] ?? $addresses->firstWhere('is_default', true)?->id);
@endphp

<div class="space-y-4">
    <a
        href="{{ route('customer.checkout.show', ['step' => 0]) }}"
        class="inline-flex items-center gap-1 text-base font-medium text-gray-500 hover:text-brand-600"
    >
        <i class="ri-arrow-left-s-line" aria-hidden="true"></i>
        Back to checkout
    </a>

    <h2 class="text-lg font-bold text-gray-900">Select Address</h2>

    @if ($addresses->isEmpty())
        <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-10 text-center">
            <p class="text-base text-gray-600">Add a delivery address to continue.</p>
            <a
                href="{{ route('customer.addresses.create', ['from' => 'checkout']) }}"
                class="mt-4 inline-flex rounded-lg bg-brand-600 px-5 py-2.5 text-base font-semibold text-white hover:bg-brand-700"
            >
                Add address
            </a>
        </div>
    @else
        <form method="POST" action="{{ route('customer.checkout.select-address') }}" class="space-y-3">
            @csrf
            @foreach ($addresses as $address)
                <label @class([
                    'flex cursor-pointer items-start gap-3 rounded-2xl border-2 bg-white p-4 shadow-sm transition',
                    'border-brand-600 bg-brand-50/30' => $selectedId == $address->id,
                    'border-gray-200 hover:border-brand-300' => $selectedId != $address->id,
                ])>
                    <input type="radio" name="address_id" value="{{ $address->id }}" @checked($selectedId == $address->id) class="mt-1 text-brand-600" required>
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
                    >
                        Edit
                    </a>
                </label>
            @endforeach

            <a
                href="{{ route('customer.addresses.create', ['from' => 'checkout']) }}"
                class="inline-flex items-center gap-1.5 text-base font-medium text-brand-600 hover:text-brand-700"
            >
                <i class="ri-add-line"></i>
                Add new address
            </a>

            <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-brand-600 px-6 py-3 text-base font-semibold text-white hover:bg-brand-700 sm:w-auto">
                Use this address
            </button>
        </form>
    @endif
</div>
