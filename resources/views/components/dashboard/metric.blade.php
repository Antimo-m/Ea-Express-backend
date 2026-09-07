@props(['metric'])
<article class="surface metric-card"><div class="metric-top"><span class="metric-label">{{ $metric['label'] }}</span><span class="metric-icon tone-{{ $metric['tone'] }}"><x-ui.icon :name="$metric['icon']" /></span></div><strong class="metric-value">{{ $metric['value'] }}</strong><span class="metric-note">{{ $metric['note'] }}</span></article>
