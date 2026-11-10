(function () {
  'use strict';
  const root = document.getElementById('disknap-analysis');
  if (!root || root.dataset.ready) return;
  root.dataset.ready = 'true';
  const range = root.querySelector('[name=days]');
  const dates = root.querySelector('#dn-custom-dates');
  const updateDates = () => {
    dates.hidden = range.value !== 'custom';
    dates.querySelectorAll('input').forEach(input => { input.required = !dates.hidden; });
  };
  range.addEventListener('change', updateDates);
  updateDates();
  if (!window.echarts) {
    root.querySelector('#dn-wakes').textContent = '图表加载失败，请刷新页面。';
    return;
  }
  const data = JSON.parse(root.querySelector('#dn-analysis-data').textContent);
  const charts = [];
  const escape = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const foreground = getComputedStyle(root).color;
  let surface = document.body;
  while (surface.parentElement && ['transparent', 'rgba(0, 0, 0, 0)'].includes(getComputedStyle(surface).backgroundColor)) surface = surface.parentElement;
  const background = getComputedStyle(surface).backgroundColor;
  function palette(index) {
    const probe = document.createElement('span');
    probe.style.color = `var(--dn-c${index % 6})`;
    root.append(probe);
    const color = getComputedStyle(probe).color;
    probe.remove();
    return color;
  }
  const labels = data.series.map(s => {
    const duplicate = data.series.filter(other => other.label === s.label).length > 1;
    return duplicate ? `${s.label} · ${s.id}` : s.label;
  });
  function draw(selector, mode) {
    const horizontal = mode === 'distribution', rate = mode === 'rate';
    const host = root.querySelector(selector);
    const categories = horizontal ? ['不足 30 分钟', '30–60 分钟', '1–3 小时', '3–6 小时', '6 小时及以上'] : data.bins.map(bin => bin.label);
    const rows = data.series.map(s => horizontal ? s.distribution : s.periods.map(p => rate ? (p.awake + p.sleep > 0 ? 100 * p.sleep / (p.awake + p.sleep) : null) : (p.awake + p.sleep > 0 || p.wakes > 0 ? p.wakes : null)));
    if (!rows.length || !rows.some(row => row.some(value => value !== null && (!horizontal || value > 0)))) {
      host.innerHTML = `<p class="dn-empty">${horizontal ? '还没有足够的休眠记录。' : '暂无数据。'}</p>`;
      return;
    }
    const plot = document.createElement('div');
    plot.className = 'dn-plot';
    host.append(plot);
    const chart = window.echarts.init(plot, null, {renderer: 'svg'});
    const scaleLabel = {hour: '小时', day: '天', week: '周', month: '月', year: '年'}[data.granularity];
    if (!horizontal) host.insertAdjacentHTML('beforebegin', `<p class="muted">按${scaleLabel}统计</p>`);
    const categoryAxis = {type: 'category', data: categories, inverse: horizontal,
      axisTick: {show: false}, axisLine: {show: false},
      axisLabel: {color: foreground, fontSize: 12, interval: horizontal ? 0 : 'auto', hideOverlap: true, margin: 14}};
    const valueAxis = {type: 'value', min: 0, minInterval: rate ? undefined : 1, max: rate ? 100 : undefined, name: rate ? '休眠率（%）' : '次数',
      nameTextStyle: {color: foreground, padding: [0, 0, 0, 12]},
      axisLabel: {color: foreground, fontSize: 12},
      splitLine: {lineStyle: {color: foreground, opacity: .1, type: 'dashed'}}};
    chart.setOption({
      animation: !window.matchMedia('(prefers-reduced-motion: reduce)').matches,
      textStyle: {fontFamily: getComputedStyle(root).fontFamily},
      aria: {enabled: true},
      grid: {left: horizontal ? 104 : 44, right: 24, top: 68, bottom: 36},
      legend: {type: 'scroll', top: 10, left: 0, icon: 'roundRect', itemWidth: 12, itemHeight: 8,
        itemGap: 22, textStyle: {color: foreground, fontSize: 13}, pageTextStyle: {color: foreground},
        data: labels},
      tooltip: {trigger: 'axis', confine: true, axisPointer: {type: rate ? 'line' : 'shadow'},
        backgroundColor: background, borderWidth: 0, textStyle: {color: foreground},
        formatter: points => {
          const rows = Array.isArray(points) ? points : [points];
          const index = rows[0]?.dataIndex;
          const title = horizontal ? categories[index] : data.bins[index]?.range;
          return [escape(title ?? ''), ...rows.map(point => {
            const value = point.value;
            const label = typeof value !== 'number' ? '暂无记录' : rate ? `${Math.round(value * 10) / 10}%` : `${value} 次`;
            return `${point.marker}${escape(point.seriesName)}　${escape(label)}`;
          })].join('<br>');
        }},
      xAxis: horizontal ? valueAxis : categoryAxis,
      yAxis: horizontal ? categoryAxis : valueAxis,
      series: data.series.map((s, i) => ({
        id: s.id, name: labels[i], type: rate ? 'line' : 'bar', data: rows[i], connectNulls: false, symbolSize: 6, lineStyle: {width: 2.5}, barMaxWidth: horizontal ? 14 : 20, barMinHeight: 1,
        itemStyle: {color: palette(s.color_index ?? i), borderRadius: horizontal ? [0, 4, 4, 0] : [4, 4, 0, 0]},
        emphasis: {focus: 'series'}
      }))
    });
    charts.push(chart);
    const header = `<tr><th scope="col">${horizontal ? '休眠时长' : '时间'}</th>${labels.map(label => `<th scope="col">${escape(label)}</th>`).join('')}</tr>`;
    const values = categories.map((label, index) => `<tr><th scope="row">${escape(label)}</th>${rows.map(row => `<td>${row[index] === null ? '暂无记录' : rate ? `${Math.round(row[index] * 10) / 10}%` : `${row[index]} 次`}</td>`).join('')}</tr>`).join('');
    host.insertAdjacentHTML('beforeend', `<details><summary>查看数据</summary><div class="scroll"><table><thead>${header}</thead><tbody>${values}</tbody></table></div></details>`);
  }
  draw('#dn-sleep-rate', 'rate');
  draw('#dn-wakes', 'wakes');
  draw('#dn-distribution', 'distribution');
  const resize = () => charts.forEach(chart => chart.resize());
  if (window.ResizeObserver) new ResizeObserver(resize).observe(root);
  else window.addEventListener('resize', resize);
})();
