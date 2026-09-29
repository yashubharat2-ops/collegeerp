<form class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" method="GET" action="{{ route('inventory-reports.index') }}">
    <input type="hidden" name="report" value="{{ $report }}">

    @if(in_array('category_id', $visible, true))
        <div>
            <label class="label" for="category_id">Category</label>
            <select class="input" id="category_id" name="category_id">
                <option value="">All categories</option>
                @foreach($filterOptions['categories'] as $category)
                    <option value="{{ $category->id }}" @selected((string) $filters['category_id'] === (string) $category->id)>{{ $category->name }} ({{ $category->code }})</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('item_id', $visible, true))
        <div>
            <label class="label" for="item_id">Item / asset</label>
            <select class="input" id="item_id" name="item_id">
                <option value="">All items</option>
                @foreach($filterOptions['items'] as $item)
                    <option value="{{ $item->id }}" @selected((string) $filters['item_id'] === (string) $item->id)>{{ $item->name }} ({{ $item->code }})</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('item_type', $visible, true))
        <div>
            <label class="label" for="item_type">Item type</label>
            <select class="input" id="item_type" name="item_type">
                <option value="">All types</option>
                @foreach($filterOptions['itemTypes'] as $option)
                    <option value="{{ $option }}" @selected($filters['item_type'] === $option)>{{ ucfirst($option) }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('item_status', $visible, true))
        <div>
            <label class="label" for="item_status">Item status</label>
            <select class="input" id="item_status" name="item_status">
                <option value="">All statuses</option>
                @foreach($filterOptions['itemStatuses'] as $option)
                    <option value="{{ $option }}" @selected($filters['item_status'] === $option)>{{ ucfirst($option) }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('asset_status', $visible, true))
        <div>
            <label class="label" for="asset_status">Asset status</label>
            <select class="input" id="asset_status" name="asset_status">
                <option value="">All statuses</option>
                @foreach($filterOptions['assetStatuses'] as $option)
                    <option value="{{ $option }}" @selected($filters['asset_status'] === $option)>{{ ucfirst($option) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="custody">Current custody</label>
            <select class="input" id="custody" name="custody">
                <option value="">All assets</option>
                @foreach($filterOptions['custodyOptions'] as $option)
                    <option value="{{ $option }}" @selected($filters['custody'] === $option)>{{ ucfirst($option) }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('vendor_id', $visible, true))
        <div>
            <label class="label" for="vendor_id">Vendor</label>
            <select class="input" id="vendor_id" name="vendor_id">
                <option value="">All vendors</option>
                @foreach($filterOptions['vendors'] as $vendor)
                    <option value="{{ $vendor->id }}" @selected((string) $filters['vendor_id'] === (string) $vendor->id)>{{ $vendor->name }} ({{ $vendor->code }})</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('purchase_order_id', $visible, true))
        <div>
            <label class="label" for="purchase_order_id">Purchase order ID</label>
            <input class="input" id="purchase_order_id" name="purchase_order_id" type="number" min="1" value="{{ $filters['purchase_order_id'] }}">
        </div>
    @endif

    @if(in_array('transaction_type', $visible, true))
        <div>
            <label class="label" for="transaction_type">Transaction type</label>
            <select class="input" id="transaction_type" name="transaction_type">
                <option value="">All types</option>
                @foreach($filterOptions['transactionTypes'] as $option)
                    <option value="{{ $option }}" @selected($filters['transaction_type'] === $option)>{{ ucfirst(str_replace('_', ' ', $option)) }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('adjustment_type', $visible, true))
        <div>
            <label class="label" for="adjustment_type">Adjustment / stock-out type</label>
            <select class="input" id="adjustment_type" name="adjustment_type">
                <option value="">All adjustment entries</option>
                @foreach($filterOptions['adjustmentTypes'] as $option)
                    <option value="{{ $option }}" @selected($filters['adjustment_type'] === $option)>{{ ucfirst(str_replace('_', ' ', $option)) }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('direction', $visible, true))
        <div>
            <label class="label" for="direction">Direction</label>
            <select class="input" id="direction" name="direction">
                <option value="">Both</option>
                @foreach($filterOptions['directions'] as $option)
                    <option value="{{ $option }}" @selected($filters['direction'] === $option)>{{ ucfirst($option) }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('purchase_status', $visible, true))
        <div>
            <label class="label" for="purchase_status">Purchase order status</label>
            <select class="input" id="purchase_status" name="purchase_status">
                <option value="">All statuses</option>
                @foreach($filterOptions['purchaseStatuses'] as $option)
                    <option value="{{ $option }}" @selected($filters['purchase_status'] === $option)>{{ ucfirst(str_replace('_', ' ', $option)) }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('issued_to_type', $visible, true))
        <div>
            <label class="label" for="issued_to_type">Recipient type</label>
            <select class="input" id="issued_to_type" name="issued_to_type">
                <option value="">All recipients</option>
                @foreach($filterOptions['recipientTypes'] as $option)
                    <option value="{{ $option }}" @selected($filters['issued_to_type'] === $option)>{{ ucfirst($option) }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('assignment_status', $visible, true))
        <div>
            <label class="label" for="assignment_status">Assignment status</label>
            <select class="input" id="assignment_status" name="assignment_status">
                <option value="">All statuses</option>
                @foreach($filterOptions['assignmentStatuses'] as $option)
                    <option value="{{ $option }}" @selected($filters['assignment_status'] === $option)>{{ ucfirst($option) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="assigned_to_type">Assigned person type</label>
            <select class="input" id="assigned_to_type" name="assigned_to_type">
                <option value="">All people</option>
                @foreach($filterOptions['assigneeTypes'] as $option)
                    <option value="{{ $option }}" @selected($filters['assigned_to_type'] === $option)>{{ ucfirst($option) }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('maintenance_status', $visible, true))
        <div>
            <label class="label" for="maintenance_status">Maintenance status</label>
            <select class="input" id="maintenance_status" name="maintenance_status">
                <option value="">All statuses</option>
                @foreach($filterOptions['maintenanceStatuses'] as $option)
                    <option value="{{ $option }}" @selected($filters['maintenance_status'] === $option)>{{ ucfirst(str_replace('_', ' ', $option)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="maintenance_type">Maintenance type</label>
            <select class="input" id="maintenance_type" name="maintenance_type">
                <option value="">All types</option>
                @foreach($filterOptions['maintenanceTypes'] as $option)
                    <option value="{{ $option }}" @selected($filters['maintenance_type'] === $option)>{{ ucfirst($option) }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if(in_array('threshold', $visible, true))
        <div>
            <label class="label" for="threshold">Low stock at or below</label>
            <input class="input" id="threshold" name="threshold" type="number" min="0" max="9999999999.99" step="0.01" value="{{ $filters['threshold'] }}" required>
        </div>
    @endif

    @if(in_array('search', $visible, true))
        <div>
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="search" maxlength="100" value="{{ $filters['search'] }}" placeholder="Name, code{{ in_array($report, ['current-stock', 'low-stock', 'assets'], true) ? ', serial' : '' }}">
        </div>
    @endif

    @if(in_array('from', $visible, true))
        <div>
            <label class="label" for="from">From date</label>
            <input class="input" id="from" name="from" type="date" value="{{ $filters['from'] }}">
        </div>
        <div>
            <label class="label" for="to">To date</label>
            <input class="input" id="to" name="to" type="date" value="{{ $filters['to'] }}">
        </div>
    @endif

    <div class="flex items-end gap-2">
        <button class="button w-full sm:w-auto" type="submit">Filter</button>
        <a class="inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 sm:w-auto" href="{{ route('inventory-reports.index', ['report' => $report]) }}">Reset</a>
    </div>
</form>
