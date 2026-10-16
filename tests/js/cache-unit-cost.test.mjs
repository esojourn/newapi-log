import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const template = readFileSync(new URL('../../resources/views/admin/dashboard.blade.php', import.meta.url), 'utf8');
const seriesFunction = template.slice(template.indexOf('        function cacheSeries('), template.indexOf('        const initialCache'));
const applyFunction = template.slice(template.indexOf('        function applyCacheFilter('), template.indexOf('        function updateCacheModelButtons('));

function source(dates, amounts, tokens) {
    const values = array => Object.fromEntries(dates.map((date, i) => [date, array[i]]));
    const zeros = values(dates.map(() => 0));
    return {
        cache_tokens: zeros, cache_creation_tokens: zeros, uncached_prompt_tokens: zeros,
        request_count: zeros, amounts: values(amounts), total_tokens: values(tokens),
    };
}

for (const dates of [['09-10 10:00', '09-10 11:00', '09-10 12:00'], ['2026-09-08', '2026-09-09', '2026-09-10']]) {
    test(`unit costs use the matching amounts and total tokens: ${dates[0]}`, () => {
        const result = runInNewContext(`${seriesFunction}\ncacheSeries(source).unitCosts`, {
            dates, source: source(dates, [2, 0, 5], [1e6, 100, 0]),
        });
        assert.deepEqual(Array.from(result), [2, 0, null]);
    });
}

test('filter updates replace unit costs, preserve precision and clear empty buckets', () => {
    const dates = ['a', 'b', 'c'];
    let updates = 0;
    const context = {
        dates,
        source: source(dates, [1, 2, 0], [3e6, 1e6, 0]),
        cacheChart: { data: { datasets: Array.from({ length: 8 }, () => ({ data: [] })) }, update: () => updates++ },
        cacheChannelFilter: { selectedOptions: [{ textContent: '渠道 A' }] },
        cacheScopeLabel: {}, cacheSavedAmount: {}, fmtAmount: value => value.toFixed(4),
    };
    runInNewContext(`${seriesFunction}\n${applyFunction}\napplyCacheFilter(source, 0, '7', 'model-a');`, context);
    assert.ok(Math.abs(context.cacheChart.data.datasets[7].data[0] - 1 / 3) < 1e-12);
    assert.deepEqual(Array.from(context.cacheChart.data.datasets[7].data).slice(1), [2, null]);
    assert.equal(context.cacheScopeLabel.textContent, '渠道 A · model-a');
    context.source = source(dates, [0, 0, 0], [0, 0, 0]);
    runInNewContext(`${seriesFunction}\n${applyFunction}\napplyCacheFilter(source, 0, '', '');`, context);
    assert.deepEqual(Array.from(context.cacheChart.data.datasets[7].data), [null, null, null]);
    assert.equal(updates, 2);
});
