window.NocApp = window.NocApp || {};
window.NocApp.utils = window.NocApp.utils || {};

window.playNotificationSound = function (soundType) {
    soundType = soundType || localStorage.getItem('notification-sound') || 'soft';

    try {
        var AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) {
            return;
        }

        if (!window.__notificationAudioContext) {
            window.__notificationAudioContext = new AudioContextClass();
        }

        var context = window.__notificationAudioContext;
        var startAt = context.currentTime + 0.02;

        if (context.state === 'suspended' && typeof context.resume === 'function') {
            context.resume();
        }

        function scheduleTone(type, frequency, delay, duration, volume) {
            var oscillator = context.createOscillator();
            var gain = context.createGain();
            var toneStart = startAt + delay;

            oscillator.type = type;
            oscillator.frequency.setValueAtTime(frequency, toneStart);
            gain.gain.setValueAtTime(0.0001, toneStart);
            gain.gain.exponentialRampToValueAtTime(volume, toneStart + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, toneStart + duration);

            oscillator.connect(gain);
            gain.connect(context.destination);
            oscillator.start(toneStart);
            oscillator.stop(toneStart + duration + 0.02);
        }

        switch (soundType) {
            case 'chime':
                scheduleTone('sine', 523.25, 0, 0.22, 0.12);
                scheduleTone('sine', 659.25, 0.18, 0.26, 0.09);
                break;
            case 'digital':
                scheduleTone('square', 880, 0, 0.08, 0.08);
                scheduleTone('square', 1180, 0.1, 0.08, 0.06);
                break;
            case 'bell':
                scheduleTone('triangle', 880, 0, 0.35, 0.14);
                scheduleTone('sine', 1320, 0.08, 0.45, 0.08);
                break;
            case 'soft':
            default:
                scheduleTone('sine', 720, 0, 0.18, 0.09);
                scheduleTone('sine', 960, 0.12, 0.22, 0.05);
                break;
        }
    } catch (e) {
        console.warn('Could not play notification sound', e);
    }
};

function populateDashboardTrendSummary(trend, granularity) {
    var mount = document.getElementById('trend-chart-summary');
    if (!mount || !trend) {
        return;
    }
    granularity = granularity || 'daily';
    var vals = trend.values || [];
    var labels = trend.labels || [];
    var dates = trend.dates || [];
    var sum = vals.reduce(function (acc, v) {
        return acc + (typeof v === 'number' ? v : 0);
    }, 0);
    var peak = Math.max.apply(null, vals.concat([0]));
    var peakIdx = vals.indexOf(peak);
    var buckets = Math.max(vals.length, 1);
    var avg = sum / buckets;
    var peakLabel = peakIdx >= 0 ? (labels[peakIdx] || '') : '';
    var avgUnit = granularity === 'weekly' ? 'avg / week' : 'avg / day';
    if (peakIdx >= 0 && dates[peakIdx]) {
        try {
            var parsedPeak = new Date(dates[peakIdx] + 'T12:00:00');
            if (!Number.isNaN(parsedPeak.getTime())) {
                peakLabel = parsedPeak.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
            }
        } catch (e) {
            /* ignore */
        }
    }

    mount.innerHTML = ''
        + '<span class="dashboard-trend-chip"><strong>' + sum + '</strong> Total</span>'
        + '<span class="dashboard-trend-chip"><strong>' + avg.toFixed(1) + '</strong> ' + avgUnit + '</span>'
        + '<span class="dashboard-trend-chip">Peak <strong>' + peak + '</strong>'
        + (peakLabel ? ' <span class="dashboard-trend-chip-sub">(' + peakLabel + ')</span>' : '')
        + '</span>';
}

function debounce(fn, wait) {
    var timeout;

    return function () {
        var context = this;
        var args = arguments;

        clearTimeout(timeout);
        timeout = setTimeout(function () {
            fn.apply(context, args);
        }, wait);
    };
}

window.NocApp.utils.debounce = window.NocApp.utils.debounce || debounce;

function createChartTooltip(container, extraClass) {
    var tooltip = document.createElement('div');
    tooltip.className = extraClass ? 'chart-tooltip ' + extraClass : 'chart-tooltip';
    container.appendChild(tooltip);
    return tooltip;
}

function setupCanvas(canvas) {
    var ratio = window.devicePixelRatio || 1;
    var width = canvas.clientWidth;
    var height = canvas.clientHeight;

    canvas.width = width * ratio;
    canvas.height = height * ratio;

    var ctx = canvas.getContext('2d');
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);

    return {
        ctx: ctx,
        width: width,
        height: height
    };
}

function drawBarChart(canvas, config, tooltip) {
    var setup = setupCanvas(canvas);
    var ctx = setup.ctx;
    var width = setup.width;
    var height = setup.height;
    var padding = { top: 18, right: 18, bottom: 40, left: 42 };
    var labels = config.labels || [];
    var values = config.values || [];
    var colors = config.colors || [];
    var maxValue = Math.max.apply(null, values.concat([1]));
    var chartWidth = width - padding.left - padding.right;
    var chartHeight = height - padding.top - padding.bottom;
    var barSlot = chartWidth / Math.max(labels.length, 1);
    var barWidth = Math.min(60, barSlot * 0.56);
    var hoverIndex = -1;
    var bars = [];

    function render() {
        ctx.clearRect(0, 0, width, height);
        ctx.lineWidth = 1;
        ctx.strokeStyle = '#dce4ee';
        ctx.fillStyle = '#66758a';
        ctx.font = '12px Segoe UI';

        for (var i = 0; i < 5; i += 1) {
            var gridValue = maxValue * (i / 4);
            var y = padding.top + chartHeight - (chartHeight * (i / 4));
            ctx.beginPath();
            ctx.moveTo(padding.left, y);
            ctx.lineTo(width - padding.right, y);
            ctx.stroke();
            ctx.fillText(Math.round(gridValue).toString(), 8, y + 4);
        }

        bars = [];

        labels.forEach(function (label, index) {
            var value = values[index] || 0;
            var x = padding.left + (barSlot * index) + ((barSlot - barWidth) / 2);
            var barHeight = maxValue === 0 ? 0 : (value / maxValue) * (chartHeight - 10);
            var y = padding.top + chartHeight - barHeight;
            var radius = 12;

            ctx.beginPath();
            ctx.moveTo(x, y + radius);
            ctx.quadraticCurveTo(x, y, x + radius, y);
            ctx.lineTo(x + barWidth - radius, y);
            ctx.quadraticCurveTo(x + barWidth, y, x + barWidth, y + radius);
            ctx.lineTo(x + barWidth, padding.top + chartHeight);
            ctx.lineTo(x, padding.top + chartHeight);
            ctx.closePath();
            ctx.fillStyle = hoverIndex === index ? '#0f172a' : (colors[index] || '#1d4ed8');
            ctx.fill();

            ctx.fillStyle = '#475569';
            ctx.textAlign = 'center';
            ctx.fillText(label, x + (barWidth / 2), height - 12);

            bars.push({
                index: index,
                label: label,
                value: value,
                x: x,
                y: y,
                width: barWidth,
                height: barHeight
            });
        });
    }

    canvas.onmousemove = function (event) {
        var rect = canvas.getBoundingClientRect();
        var x = event.clientX - rect.left;
        var y = event.clientY - rect.top;
        var found = -1;

        bars.forEach(function (bar) {
            if (x >= bar.x && x <= bar.x + bar.width && y >= bar.y && y <= padding.top + chartHeight) {
                found = bar.index;
            }
        });

        if (found !== hoverIndex) {
            hoverIndex = found;
            render();
        }

        if (found >= 0) {
            var active = bars[found];
            tooltip.style.display = 'block';
            tooltip.innerHTML = '<strong>' + active.label + '</strong><br>' + active.value + ' tickets';
            tooltip.style.left = (active.x + (active.width / 2)) + 'px';
            tooltip.style.top = active.y + 'px';
        } else {
            tooltip.style.display = 'none';
        }
    };

    canvas.onmouseleave = function () {
        hoverIndex = -1;
        tooltip.style.display = 'none';
        render();
    };

    render();

    return render;
}

function appendStraightLineTop(ctx, points) {
    var i;
    for (i = 1; i < points.length; i += 1) {
        ctx.lineTo(points[i].x, points[i].y);
    }
}

function drawLineChart(canvas, config, tooltip) {
    var setup = setupCanvas(canvas);
    var ctx = setup.ctx;
    var width = setup.width;
    var height = setup.height;
    var compact = Boolean(config.compact);
    var padding = compact
        ? { top: 12, right: 10, bottom: 26, left: 34 }
        : { top: 22, right: 22, bottom: 38, left: 46 };
    var labels = config.labels || [];
    var values = config.values || [];
    var isoDates = config.dates || [];
    var breakdown = config.breakdown || [];
    var bucketUnit = config.bucketUnit === 'week' ? 'week' : 'day';
    var chartWidth = width - padding.left - padding.right;
    var chartHeight = height - padding.top - padding.bottom;
    var maxValue = Math.max.apply(null, values.concat([1]));
    var hoverIndex = -1;
    var points = [];
    var lastTooltipIdx = -1;
    var periodTotal = values.reduce(function (acc, v) {
        return acc + (typeof v === 'number' ? v : 0);
    }, 0);
    var isDark = typeof document !== 'undefined'
        && document.documentElement
        && document.documentElement.classList.contains('theme-dark');
    var gridColor = isDark ? 'rgba(148, 163, 184, 0.22)' : '#e2e8f0';
    var axisText = isDark ? '#94a3b8' : '#64748b';
    var linePrimary = isDark ? '#60a5fa' : '#2563eb';
    var lineGlow = isDark ? 'rgba(96, 165, 250, 0.12)' : 'rgba(37, 99, 235, 0.08)';
    var pointHover = isDark ? '#fbbf24' : '#ea580c';
    var bandPad = labels.length <= 1 ? chartWidth / 2 : chartWidth / Math.max(labels.length - 1, 1) / 2 + 6;

    function pointX(index) {
        if (labels.length <= 1) {
            return padding.left + (chartWidth / 2);
        }

        return padding.left + ((chartWidth / (labels.length - 1)) * index);
    }

    function pointY(value) {
        return padding.top + chartHeight - ((value / maxValue) * (chartHeight - 14));
    }

    function normalizeTrendBd(raw) {
        var o = raw && typeof raw === 'object' ? raw : {};
        function num(key) {
            var v = o[key];
            if (typeof v === 'number' && !Number.isNaN(v)) {
                return v;
            }
            var parsed = parseInt(v, 10);
            return Number.isNaN(parsed) ? 0 : parsed;
        }
        return {
            open: num('open'),
            in_progress: num('in_progress'),
            closed: num('closed'),
            unassigned: num('unassigned')
        };
    }

    function render() {
        ctx.clearRect(0, 0, width, height);
        ctx.font = (compact ? '10px' : '11px') + ' Segoe UI, system-ui, sans-serif';
        ctx.strokeStyle = gridColor;
        ctx.fillStyle = axisText;
        ctx.lineWidth = 1;

        for (var gi = 0; gi < 5; gi += 1) {
            var gy = padding.top + chartHeight - (chartHeight * (gi / 4));
            var gval = Math.round(maxValue * (gi / 4));
            ctx.beginPath();
            ctx.moveTo(padding.left, gy);
            ctx.lineTo(width - padding.right, gy);
            ctx.stroke();
            ctx.fillText(gval.toString(), 8, gy + 4);
        }

        points = values.map(function (value, index) {
            return {
                index: index,
                label: labels[index],
                iso: isoDates[index] || '',
                value: value,
                x: pointX(index),
                y: pointY(value)
            };
        });

        var baseline = padding.top + chartHeight;

        if (points.length) {
            var grad = ctx.createLinearGradient(0, padding.top, 0, baseline);
            grad.addColorStop(0, isDark ? 'rgba(96, 165, 250, 0.22)' : 'rgba(37, 99, 235, 0.14)');
            grad.addColorStop(1, isDark ? 'rgba(96, 165, 250, 0.02)' : 'rgba(37, 99, 235, 0.02)');

            ctx.beginPath();
            ctx.moveTo(points[0].x, baseline);
            ctx.lineTo(points[0].x, points[0].y);
            appendStraightLineTop(ctx, points);
            ctx.lineTo(points[points.length - 1].x, baseline);
            ctx.closePath();
            ctx.fillStyle = grad;
            ctx.fill();

            ctx.save();
            ctx.beginPath();
            ctx.moveTo(points[0].x, points[0].y);
            appendStraightLineTop(ctx, points);
            ctx.strokeStyle = linePrimary;
            ctx.lineWidth = 2;
            ctx.lineJoin = 'miter';
            ctx.lineCap = 'square';
            ctx.shadowColor = lineGlow;
            ctx.shadowBlur = 3;
            ctx.stroke();
            ctx.shadowBlur = 0;
            ctx.restore();

            if (hoverIndex >= 0 && hoverIndex < points.length) {
                var hp = points[hoverIndex];
                ctx.save();
                ctx.setLineDash([4, 4]);
                ctx.strokeStyle = isDark ? 'rgba(148, 163, 184, 0.55)' : 'rgba(100, 116, 139, 0.45)';
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.moveTo(hp.x, padding.top + 6);
                ctx.lineTo(hp.x, baseline);
                ctx.stroke();
                ctx.restore();
            }

            points.forEach(function (point, index) {
                var r = hoverIndex === index ? 7 : 4;
                ctx.beginPath();
                ctx.arc(point.x, point.y, r + (hoverIndex === index ? 2 : 0), 0, Math.PI * 2);
                ctx.fillStyle = isDark ? '#0f172a' : '#ffffff';
                ctx.fill();
                ctx.beginPath();
                ctx.arc(point.x, point.y, r, 0, Math.PI * 2);
                ctx.fillStyle = hoverIndex === index ? pointHover : linePrimary;
                ctx.fill();

                ctx.fillStyle = axisText;
                ctx.textAlign = 'center';
                ctx.fillText(point.label, point.x, height - (compact ? 6 : 8));
            });
        } else {
            ctx.fillStyle = axisText;
            ctx.textAlign = 'center';
            ctx.fillText('No trend data available', width / 2, height / 2);
        }
    }

    canvas.onmousemove = function (event) {
        var rect = canvas.getBoundingClientRect();
        var x = event.clientX - rect.left;
        var found = -1;
        var bestDist = Infinity;

        points.forEach(function (point) {
            var d = Math.abs(x - point.x);
            if (d <= bandPad && d < bestDist) {
                bestDist = d;
                found = point.index;
            }
        });

        if (found !== hoverIndex) {
            hoverIndex = found;
            render();
        }

        if (found >= 0) {
            var active = points[found];
            tooltip.style.display = 'block';
            var titleLine = active.iso
                ? (function () {
                    try {
                        var parsed = new Date(active.iso + 'T12:00:00');
                        if (!Number.isNaN(parsed.getTime())) {
                            return parsed.toLocaleDateString(undefined, {
                                weekday: 'short',
                                month: 'short',
                                day: 'numeric',
                                year: 'numeric'
                            });
                        }
                    } catch (e) {
                        /* ignore */
                    }
                    return active.label;
                }())
                : active.label;
            var pct = periodTotal > 0 ? Math.round((active.value / periodTotal) * 100) : 0;
            var bdNorm = normalizeTrendBd(breakdown[found]);
            var bucketPhrase = bucketUnit === 'week' ? 'that week' : 'that day';
            var showTrendBd = breakdown.length > 0 && breakdown.length === values.length;

            if (found !== lastTooltipIdx) {
                lastTooltipIdx = found;
                if (showTrendBd) {
                    tooltip.innerHTML = ''
                        + '<div class="chart-tooltip-head">' + escapeHtmlChartText(titleLine) + '</div>'
                        + '<div class="chart-tooltip-trend-total">'
                        + '<span class="chart-tooltip-trend-total-lbl">Created</span>'
                        + '<span class="chart-tooltip-trend-total-val">' + String(active.value) + '</span>'
                        + '</div>'
                        + '<div class="chart-tooltip-trend-bd">'
                        + '<span class="chart-tooltip-row chart-tooltip-row--open"><span class="lbl">'
                        + '<span class="chart-tooltip-dot" aria-hidden="true"></span>Open</span><b>'
                        + String(bdNorm.open) + '</b></span>'
                        + '<span class="chart-tooltip-row chart-tooltip-row--prog"><span class="lbl">'
                        + '<span class="chart-tooltip-dot" aria-hidden="true"></span>In progress</span><b>'
                        + String(bdNorm.in_progress) + '</b></span>'
                        + '<span class="chart-tooltip-row chart-tooltip-row--closed"><span class="lbl">'
                        + '<span class="chart-tooltip-dot" aria-hidden="true"></span>Closed</span><b>'
                        + String(bdNorm.closed) + '</b></span>'
                        + '<span class="chart-tooltip-row chart-tooltip-row--unas"><span class="lbl">'
                        + '<span class="chart-tooltip-dot" aria-hidden="true"></span>Unassigned</span><b>'
                        + String(bdNorm.unassigned) + '</b></span>'
                        + '</div>'
                        + '<small class="chart-tooltip-muted chart-tooltip-trend-foot">'
                        + String(pct) + '% of period · counts are for tickets created ' + bucketPhrase + '</small>';
                } else {
                    tooltip.innerHTML = ''
                        + '<strong>' + escapeHtmlChartText(titleLine) + '</strong>'
                        + '<br><span class="chart-tooltip-value">' + String(active.value) + '</span> new ticket'
                        + (active.value === 1 ? '' : 's')
                        + '<br><small class="chart-tooltip-muted">' + String(pct) + '% of tickets in this period</small>';
                }
            }

            tooltip.style.left = active.x + 'px';
            tooltip.style.top = active.y + 'px';
            if (tooltip.classList) {
                tooltip.classList.toggle('chart-tooltip--below', active.y < (compact ? 44 : 56));
            }
        } else {
            lastTooltipIdx = -1;
            tooltip.style.display = 'none';
            if (tooltip.classList) {
                tooltip.classList.remove('chart-tooltip--below');
            }
        }
    };

    canvas.onmouseleave = function () {
        hoverIndex = -1;
        lastTooltipIdx = -1;
        tooltip.style.display = 'none';
        if (tooltip.classList) {
            tooltip.classList.remove('chart-tooltip--below');
        }
        render();
    };

    render();

    return render;
}

function escapeHtmlChartText(raw) {
    return String(raw || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function drawDonutChart(canvas, config, tooltip) {
    var ctx;
    var width = 0;
    var height = 0;
    var labels = config.labels || [];
    var values = config.values || [];
    var colors = config.colors || [];
    var sliceGeom = [];
    var hoverIndex = -1;
    var cx = 0;
    var cy = 0;
    var radius = 0;
    var innerRadius = 0;
    var lastTotal = 0;

    function hitSlice(mx, my) {
        if (!sliceGeom.length || radius <= 0) {
            return -1;
        }
        var i;
        for (i = sliceGeom.length - 1; i >= 0; i -= 1) {
            var s = sliceGeom[i];
            ctx.beginPath();
            ctx.arc(cx, cy, radius, s.start, s.end, false);
            ctx.arc(cx, cy, innerRadius, s.end, s.start, true);
            ctx.closePath();
            if (ctx.isPointInPath(mx, my)) {
                return i;
            }
        }
        return -1;
    }

    function render() {
        var setup = setupCanvas(canvas);
        ctx = setup.ctx;
        width = setup.width;
        height = setup.height;

        var total = values.reduce(function (acc, v) {
            return acc + (typeof v === 'number' ? v : 0);
        }, 0);
        lastTotal = total;

        var isDark = typeof document !== 'undefined'
            && document.documentElement
            && document.documentElement.classList.contains('theme-dark');
        var muted = isDark ? '#94a3b8' : '#475569';
        var holeFill = isDark ? '#1e293b' : '#ffffff';

        cx = width / 2;
        cy = height / 2;
        radius = Math.min(width, height) * 0.36;
        innerRadius = radius * 0.62;

        sliceGeom = [];
        var start = -Math.PI / 2;
        values.forEach(function (v, i) {
            var sliceAng = total > 0 ? (v / total) * Math.PI * 2 : 0;
            if (sliceAng <= 0.00001) {
                return;
            }
            sliceGeom.push({
                start: start,
                end: start + sliceAng,
                index: i,
                value: v,
                label: labels[i] || ('Slice ' + (i + 1)),
                pct: total > 0 ? Math.round((v / total) * 1000) / 10 : 0
            });
            start += sliceAng;
        });

        ctx.clearRect(0, 0, width, height);

        if (total <= 0) {
            ctx.beginPath();
            ctx.arc(cx, cy, radius, 0, Math.PI * 2);
            ctx.arc(cx, cy, innerRadius, 0, Math.PI * 2, true);
            ctx.closePath();
            ctx.fillStyle = isDark ? 'rgba(148, 163, 184, 0.18)' : '#e2e8f0';
            ctx.fill();

            ctx.beginPath();
            ctx.arc(cx, cy, innerRadius, 0, Math.PI * 2);
            ctx.fillStyle = holeFill;
            ctx.fill();

            ctx.fillStyle = muted;
            ctx.font = '600 13px Segoe UI, system-ui, sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText('0 Total', cx, cy);
            return;
        }

        sliceGeom.forEach(function (s, si) {
            ctx.beginPath();
            ctx.arc(cx, cy, radius, s.start, s.end, false);
            ctx.arc(cx, cy, innerRadius, s.end, s.start, true);
            ctx.closePath();
            ctx.fillStyle = colors[s.index] || '#64748b';
            ctx.fill();

            if (hoverIndex === si) {
                ctx.save();
                ctx.strokeStyle = isDark ? 'rgba(248, 250, 252, 0.75)' : 'rgba(15, 23, 42, 0.35)';
                ctx.lineWidth = 2;
                ctx.stroke();
                ctx.restore();
            }
        });

        ctx.beginPath();
        ctx.arc(cx, cy, innerRadius, 0, Math.PI * 2);
        ctx.fillStyle = holeFill;
        ctx.fill();

        ctx.fillStyle = muted;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.font = '700 17px Segoe UI, system-ui, sans-serif';
        ctx.fillText(total + ' Total', cx, cy - 2);
        ctx.font = '600 11px Segoe UI, system-ui, sans-serif';
        ctx.fillStyle = isDark ? '#64748b' : '#94a3b8';
        ctx.fillText('in period', cx, cy + 14);
    }

    if (tooltip) {
        canvas.onmousemove = function (event) {
            var idx = hitSlice(event.offsetX, event.offsetY);
            if (idx !== hoverIndex) {
                hoverIndex = idx;
                render();
            }

            canvas.style.cursor = idx >= 0 ? 'pointer' : 'default';

            if (idx >= 0 && sliceGeom[idx]) {
                var s = sliceGeom[idx];
                tooltip.style.display = 'block';
                tooltip.innerHTML = ''
                    + '<strong>' + escapeHtmlChartText(s.label) + '</strong>'
                    + '<br><span class="chart-tooltip-value">' + String(s.value) + '</span>'
                    + ' ticket' + (s.value === 1 ? '' : 's')
                    + '<br><small class="chart-tooltip-muted">'
                    + String(s.pct) + '% of ' + String(lastTotal) + ' created this period'
                    + '</small>';
                tooltip.style.left = event.offsetX + 'px';
                tooltip.style.top = event.offsetY + 'px';
            } else if (tooltip) {
                tooltip.style.display = 'none';
            }
        };

        canvas.onmouseleave = function () {
            hoverIndex = -1;
            canvas.style.cursor = 'default';
            tooltip.style.display = 'none';
            render();
        };
    }

    render();

    return render;
}

window.SimpleCharts = {
    instances: [],
    bar: function (canvasId, config) {
        var canvas = document.getElementById(canvasId);

        if (!canvas) {
            return;
        }

        var tooltip = createChartTooltip(canvas.parentElement);
        var redraw = drawBarChart(canvas, config, tooltip);

        window.SimpleCharts.instances.push(redraw);
    },
    line: function (canvasId, config) {
        var canvas = document.getElementById(canvasId);

        if (!canvas) {
            return;
        }

        var tooltip = createChartTooltip(canvas.parentElement, 'chart-tooltip--rich');
        var redraw = drawLineChart(canvas, config, tooltip);

        window.SimpleCharts.instances.push(redraw);
    },
    donut: function (canvasId, config) {
        var canvas = document.getElementById(canvasId);

        if (!canvas) {
            return;
        }

        var tooltip = createChartTooltip(canvas.parentElement, 'chart-tooltip--rich');
        var redraw = drawDonutChart(canvas, config, tooltip);

        window.SimpleCharts.instances.push(redraw);
    }
};

document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.getElementById('sidebar');
    var body = document.body;
    var root = document.documentElement;
    var dropdowns = Array.prototype.filter.call(
        document.querySelectorAll('[data-dropdown]'),
        function (dropdown) {
            return !dropdown.closest('#ticket-list-root');
        }
    );
    var notificationRoot = document.querySelector('[data-notification-root]');
    var themeButtons = document.querySelectorAll('[data-theme-toggle]');

    function applyTheme(theme) {
        var isDark = theme === 'dark';

        root.classList.toggle('theme-dark', isDark);
        body.classList.toggle('theme-dark', isDark);

        themeButtons.forEach(function (button) {
            var themeIcon = button.querySelector('[data-theme-icon]');
            var themeLabel = button.querySelector('[data-theme-label]');
            var nextLabel = isDark
                ? (button.getAttribute('data-theme-light-label') || 'Switch to light mode')
                : (button.getAttribute('data-theme-dark-label') || 'Switch to dark mode');

            button.setAttribute('aria-pressed', isDark ? 'true' : 'false');

            if (themeIcon) {
                themeIcon.textContent = isDark ? 'Sun' : 'Moon';
            }

            if (themeLabel) {
                themeLabel.textContent = nextLabel;
            }
        });

        document.querySelectorAll('[data-theme-status]').forEach(function (statusNode) {
            statusNode.textContent = isDark ? 'Dark mode' : 'Light mode';
        });
    }

    function toggleTheme() {
        var nextTheme = root.classList.contains('theme-dark') ? 'light' : 'dark';
        localStorage.setItem('app-theme', nextTheme);
        applyTheme(nextTheme);

        if (window.SimpleCharts && Array.isArray(window.SimpleCharts.instances)) {
            window.SimpleCharts.instances.forEach(function (redraw) {
                if (typeof redraw === 'function') {
                    redraw();
                }
            });
        }
    }

    function notificationParseMeta(notification) {
        if (!notification || typeof notification !== 'object') {
            return {};
        }
        var raw = notification.meta_json;
        if (!raw) {
            return {};
        }
        if (typeof raw === 'object') {
            return raw;
        }
        try {
            var parsed = JSON.parse(String(raw));
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (metaErr) {
            return {};
        }
    }

    function notificationResolveLink(notification) {
        if (!notification) {
            return '';
        }
        var stored = String(notification.link_url || '').trim();
        if (stored) {
            return stored;
        }
        return '';
    }

    function showLiveNotification(message, options) {
        if (!message) {
            return;
        }

        options = options || {};
        var toast = document.createElement('div');
        toast.className = 'notification-live-toast';
        toast.setAttribute('role', 'status');

        var titleEl = document.createElement('strong');
        titleEl.textContent = String(options.title || message);
        toast.appendChild(titleEl);

        if (options.subtitle) {
            var subtitleEl = document.createElement('span');
            subtitleEl.textContent = String(options.subtitle);
            toast.appendChild(subtitleEl);
        }

        var href = String(options.href || '').trim();
        if (href) {
            toast.addEventListener('click', function () {
                window.location.href = href;
            });
        }

        document.body.appendChild(toast);

        window.setTimeout(function () {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, options.durationMs || 5200);
    }

    function setupNotificationBell() {
        if (!notificationRoot || typeof window.EventSource === 'undefined') {
            return;
        }

        var countElement = notificationRoot.querySelector('[data-notification-count]');
        var pillElement = notificationRoot.querySelector('[data-notification-pill]');
        var listElement = notificationRoot.querySelector('[data-notification-list]');
        var menu = notificationRoot.querySelector('[data-notification-stream]');
        var dropdownMenu = notificationRoot.querySelector('[data-dropdown-menu]');
        var bellElement = notificationRoot.querySelector('.notification-bell');
        var lastId = 0;
        var toastedNotificationIds = {};
        var items = notificationRoot.querySelectorAll('[data-notification-id]');

        // Sound preference handling
        var soundSelect = document.getElementById('notification-sound-select');
        var soundTestBtn = document.getElementById('notification-sound-test');
        var soundEnabled = localStorage.getItem('notification-sound-enabled') !== '0';

        if (soundSelect) {
            var savedSound = localStorage.getItem('notification-sound') || 'soft';
            soundSelect.value = savedSound;
            soundSelect.addEventListener('change', function () {
                localStorage.setItem('notification-sound', soundSelect.value);
                showLiveNotification('Notification sound changed to ' + soundSelect.options[soundSelect.selectedIndex].text);
            });
        }

        // Sound enable/disable toggle
        var soundToggleBtn = document.getElementById('sound-enable-toggle');
        if (soundToggleBtn) {
            soundToggleBtn.textContent = soundEnabled ? '🔊 On' : '🔕 Off';
            soundToggleBtn.addEventListener('click', function () {
                soundEnabled = !soundEnabled;
                localStorage.setItem('notification-sound-enabled', soundEnabled ? '1' : '0');
                soundToggleBtn.textContent = soundEnabled ? '🔊 On' : '🔕 Off';
                showLiveNotification('Notification sound ' + (soundEnabled ? 'enabled' : 'muted'));
            });
        }

        if (soundTestBtn) {
            soundTestBtn.addEventListener('click', function () {
                var selectedSound = soundSelect ? soundSelect.value : (localStorage.getItem('notification-sound') || 'soft');
                localStorage.setItem('notification-sound', selectedSound);

                if (!soundEnabled) {
                    soundEnabled = true;
                    localStorage.setItem('notification-sound-enabled', '1');
                    if (soundToggleBtn) {
                        soundToggleBtn.textContent = '🔊 On';
                    }
                }

                if (typeof window.playNotificationSound === 'function') {
                    window.playNotificationSound(selectedSound);
                    showLiveNotification('Playing ' + selectedSound + ' notification sound');
                }
            });
        }

        items.forEach(function (item) {
            var id = parseInt(item.getAttribute('data-notification-id') || '0', 10);
            if (id > lastId) {
                lastId = id;
            }
        });

        if (!dropdownMenu) {
            return;
        }

        var streamUrl = dropdownMenu.getAttribute('data-notification-stream');
        var markReadUrl = dropdownMenu.getAttribute('data-notification-mark-read');

        function bindNotificationLinks() {
            notificationRoot.querySelectorAll('[data-notification-link]').forEach(function (link) {
                link.addEventListener('click', function (event) {
                    var notificationId = parseInt(link.getAttribute('data-notification-id') || '0', 10);
                    if (!notificationId || !markReadUrl) {
                        return;
                    }

                    event.preventDefault();

                    var formData = new FormData();
                    var tokenField = document.querySelector('input[name="csrf_token"]');
                    if (tokenField) {
                        formData.append('csrf_token', tokenField.value);
                    }
                    formData.append('notification_id', String(notificationId));

                    var redirectTarget = link.getAttribute('href') || window.location.href;
                    try {
                        var redirectUrl = new URL(redirectTarget, window.location.href);
                        redirectUrl.searchParams.set('open_notification_id', String(notificationId));
                        redirectTarget = redirectUrl.pathname + redirectUrl.search + redirectUrl.hash;
                    } catch (redirectUrlErr) {
                        /* keep original href */
                    }
                    formData.append('redirect_to', redirectTarget);

                    fetch(markReadUrl, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        }
                    }).finally(function () {
                        window.location.href = redirectTarget;
                    });
                }, { once: true });
            });
        }

        bindNotificationLinks();

        if (!streamUrl) {
            return;
        }

        function connectNotificationStream() {
            if (window.__notificationEventSource) {
                try {
                    window.__notificationEventSource.close();
                } catch (closeErr) {
                    /* ignore */
                }
            }

            var source = new EventSource(streamUrl + '?last_id=' + encodeURIComponent(lastId));
            window.__notificationEventSource = source;

            source.addEventListener('notifications', function (event) {
            try {
                var payload = JSON.parse(event.data);
                var previousLastId = lastId;

                if (typeof payload.latest_id === 'number') {
                    lastId = payload.latest_id;
                }

                if (countElement) {
                    countElement.textContent = payload.unread_count > 0 ? payload.unread_count + ' unread' : 'No new alerts';
                }

                if (pillElement) {
                    pillElement.textContent = String(payload.unread_count || 0);
                    pillElement.hidden = !payload.unread_count;
                }

                if (listElement && typeof payload.html === 'string') {
                    listElement.innerHTML = payload.html;
                    bindNotificationLinks();
                }

                if (payload.has_new && payload.notifications && payload.notifications.length && lastId > previousLastId) {
                    var newest = payload.notifications[0];
                    var newestId = parseInt(String(newest.id || '0'), 10);
                    if (newestId > 0 && !toastedNotificationIds[newestId]) {
                        toastedNotificationIds[newestId] = true;
                        var meta = notificationParseMeta(newest);
                        var sender = String(meta.sender_name || newest.title || 'New email');
                        var subject = String(meta.subject || '');
                        var snippet = String(meta.snippet || newest.message || '');
                        if (snippet.length > 120) {
                            snippet = snippet.slice(0, 117) + '…';
                        }
                        var subtitle = subject !== '' ? subject : snippet;
                        if (subject !== '' && snippet !== '' && snippet !== subject) {
                            subtitle = subject + ' — ' + snippet;
                        }
                        showLiveNotification(sender, {
                            title: sender,
                            subtitle: subtitle,
                            href: notificationResolveLink(newest),
                        });
                    }
                    if (localStorage.getItem('notification-sound-enabled') !== '0') {
                        var sound = localStorage.getItem('notification-sound') || 'soft';
                        window.playNotificationSound(sound);
                    }
                    if (bellElement) {
                        bellElement.classList.remove('ringing');
                        void bellElement.offsetWidth;
                        bellElement.classList.add('ringing');
                        setTimeout(function () {
                            bellElement.classList.remove('ringing');
                        }, 800);
                    }
                }
            } catch (error) {
                console.error('Notification stream parse failed', error);
            }
            });

            source.onerror = function () {
                try {
                    source.close();
                } catch (streamCloseErr) {
                    /* ignore */
                }
                window.setTimeout(connectNotificationStream, 4000);
            };
        }

        connectNotificationStream();
    }

    if (localStorage.getItem('sidebar-collapsed') === '1') {
        body.classList.add('sidebar-collapsed');
    }

    applyTheme(localStorage.getItem('app-theme') === 'dark' ? 'dark' : 'light');

    document.querySelectorAll('[data-sidebar-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (sidebar) {
                sidebar.classList.toggle('open');
            }
        });
    });

    themeButtons.forEach(function (button) {
        button.addEventListener('click', toggleTheme);
    });

    document.querySelectorAll('[data-sidebar-collapse]').forEach(function (button) {
        button.addEventListener('click', function () {
            body.classList.toggle('sidebar-collapsed');
            localStorage.setItem('sidebar-collapsed', body.classList.contains('sidebar-collapsed') ? '1' : '0');
        });
    });

    dropdowns.forEach(function (dropdown) {
        var trigger = dropdown.querySelector('[data-dropdown-trigger]');

        if (!trigger) {
            return;
        }

        trigger.addEventListener('click', function (event) {
            event.stopPropagation();
            dropdowns.forEach(function (item) {
                if (item !== dropdown) {
                    item.classList.remove('open');
                }
            });
            dropdown.classList.toggle('open');
            trigger.setAttribute('aria-expanded', dropdown.classList.contains('open') ? 'true' : 'false');
        });
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest('#ticket-list-root')) {
            return;
        }

        dropdowns.forEach(function (dropdown) {
            if (dropdown.classList.contains('open') && dropdown.contains(event.target)) {
                return;
            }
            dropdown.classList.remove('open');
            var trigger = dropdown.querySelector('[data-dropdown-trigger]');
            if (trigger) {
                trigger.setAttribute('aria-expanded', 'false');
            }
        });
    });

    (function initDashboardMonitoringFilter() {
        var wrap = document.getElementById('dashboard-filter-collapsible');
        var btn = document.getElementById('dashboard-filter-toggle');
        if (!wrap || !btn) {
            return;
        }

        var storageKey = 'dashboardFiltersCollapsed';
        var hiddenClass = 'dashboard-filter-collapsible--hidden';

        function isCollapsed() {
            return wrap.classList.contains(hiddenClass);
        }

        function applyCollapsed(collapsed) {
            wrap.classList.toggle(hiddenClass, collapsed);
            wrap.setAttribute('aria-hidden', collapsed ? 'true' : 'false');
            btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            btn.textContent = collapsed ? 'Show filters' : 'Hide filters';
            try {
                window.localStorage.setItem(storageKey, collapsed ? '1' : '0');
            } catch (err) {
                /* ignore */
            }
        }

        btn.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            applyCollapsed(!isCollapsed());
        });

        try {
            if (window.localStorage.getItem(storageKey) === '1') {
                applyCollapsed(true);
            }
        } catch (err) {
            /* ignore */
        }

        var fromEl = document.getElementById('from_date');
        var toEl = document.getElementById('to_date');
        if (fromEl && toEl) {
            function syncBounds() {
                if (fromEl.value) {
                    toEl.min = fromEl.value;
                } else {
                    toEl.removeAttribute('min');
                }
                if (toEl.value) {
                    fromEl.max = toEl.value;
                } else {
                    fromEl.removeAttribute('max');
                }
            }

            fromEl.addEventListener('change', function () {
                if (fromEl.value && toEl.value && fromEl.value > toEl.value) {
                    toEl.value = fromEl.value;
                }
                syncBounds();
            });

            toEl.addEventListener('change', function () {
                if (fromEl.value && toEl.value && fromEl.value > toEl.value) {
                    fromEl.value = toEl.value;
                }
                syncBounds();
            });

            syncBounds();
        }
    })();

    document.querySelectorAll('[data-panel-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var targetId = button.getAttribute('data-panel-toggle');
            var panel = document.getElementById(targetId);

            if (!panel) {
                return;
            }

            panel.hidden = !panel.hidden;
            button.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
        });
    });

    document.querySelectorAll('[data-confirm]').forEach(function (element) {
        element.addEventListener('click', function (event) {
            var message = element.getAttribute('data-confirm') || 'Are you sure?';

            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    if (window.dashboardChartConfig && window.dashboardChartConfig.trendDaily) {
        var dc = window.dashboardChartConfig;

        function stripChartTooltips(shell) {
            shell.querySelectorAll('.chart-tooltip').forEach(function (node) {
                if (node.parentNode) {
                    node.parentNode.removeChild(node);
                }
            });
        }

        function rebuildTrendChart(bundle, granularity) {
            var canvas = document.getElementById('trendChart');
            if (!canvas || !bundle) {
                return;
            }
            var shell = canvas.parentElement;
            if (!shell) {
                return;
            }

            if (window.__dashboardTrendRedraw) {
                window.SimpleCharts.instances = window.SimpleCharts.instances.filter(function (fn) {
                    return fn !== window.__dashboardTrendRedraw;
                });
                window.__dashboardTrendRedraw = null;
            }

            stripChartTooltips(shell);

            var fresh = canvas.cloneNode(false);
            shell.replaceChild(fresh, canvas);

            var tooltip = createChartTooltip(shell, 'chart-tooltip--rich chart-tooltip--trend');
            window.__dashboardTrendRedraw = drawLineChart(fresh, bundle, tooltip);
            window.SimpleCharts.instances.push(window.__dashboardTrendRedraw);

            if (typeof populateDashboardTrendSummary === 'function') {
                populateDashboardTrendSummary(bundle, granularity);
            }
        }

        window.SimpleCharts.donut('statusChart', dc.status);

        var subt = document.getElementById('dashboard-trend-subtitle');
        var segBtns = document.querySelectorAll('[data-dashboard-trend-mode]');
        var trendModeKey = 'dashboardTrendGranularity';

        function storedTrendMode() {
            try {
                return window.localStorage.getItem(trendModeKey) === 'weekly' ? 'weekly' : 'daily';
            } catch (err) {
                return 'daily';
            }
        }

        function setTrendSegUi(mode) {
            segBtns.forEach(function (btn) {
                var m = btn.getAttribute('data-dashboard-trend-mode');
                btn.classList.toggle('is-active', m === mode);
            });
        }

        var initialTrendMode = storedTrendMode();
        setTrendSegUi(initialTrendMode);

        if (subt) {
            subt.textContent = initialTrendMode === 'weekly'
                ? 'Tickets summed by week (week starts Monday; created date in range).'
                : 'New tickets per day (created date in range).';
        }

        rebuildTrendChart(
            initialTrendMode === 'weekly' ? dc.trendWeekly : dc.trendDaily,
            initialTrendMode
        );

        segBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var mode = btn.getAttribute('data-dashboard-trend-mode') || 'daily';
                try {
                    window.localStorage.setItem(trendModeKey, mode);
                } catch (err) {
                    /* ignore */
                }
                setTrendSegUi(mode);
                if (subt) {
                    subt.textContent = mode === 'weekly'
                        ? 'Tickets summed by week (week starts Monday; created date in range).'
                        : 'New tickets per day (created date in range).';
                }
                rebuildTrendChart(mode === 'weekly' ? dc.trendWeekly : dc.trendDaily, mode);
            });
        });
    } else if (window.dashboardChartConfig) {
        window.SimpleCharts.bar('statusChart', window.dashboardChartConfig.status);
        var legacyTrend = window.dashboardChartConfig.trendDaily || window.dashboardChartConfig.trend || {};
        window.SimpleCharts.line('trendChart', legacyTrend);
        if (typeof populateDashboardTrendSummary === 'function') {
            populateDashboardTrendSummary(legacyTrend, 'daily');
        }
    }

    window.addEventListener('resize', debounce(function () {
        window.SimpleCharts.instances.forEach(function (redraw) {
            redraw();
        });
    }, 150));

    setupNotificationBell();
});
