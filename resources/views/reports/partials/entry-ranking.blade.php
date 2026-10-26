@if (count($entries))
    <ol class="transaction-cards">
        @foreach ($entries as $index => $entry)
            <li class="transaction-card {{ $direction }}">
                <span class="transaction-rank">{{ $index + 1 }}</span>
                <div><strong>{{ $entry['category'] }}</strong>@if ($entry['description'])<span class="description">{{ $entry['description'] }}</span>@endif<span class="entry-date">{{ $entry['date'] }}</span></div>
                <strong class="transaction-amount {{ $direction }}">{{ $ledger['currency'] }} {{ $entry['amount'] }}</strong>
            </li>
        @endforeach
    </ol>
@else
    <div class="empty"><strong>本期暂无有效{{ $direction === 'income' ? '收入' : '支出' }}记录</strong>有效记账后将显示金额排行。</div>
@endif
