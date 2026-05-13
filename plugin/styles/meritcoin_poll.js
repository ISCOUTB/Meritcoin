// meritcoin_poll.js — Polling AJAX centralizado para todas las páginas
(function () {
    'use strict';

    var POLL_INTERVAL = 20000; // 20 segundos
    var BASE_URL = M.cfg.wwwroot + '/local/meritcoin/ajax_data.php';

    // Mapa de handlers por página
    var handlers = {

        dashboard: function (data) {
            // Actualizar balance
            var balEl = document.querySelector('.mrt-balance-value');
            if (balEl && data.balance !== undefined) {
                var ticker = balEl.querySelector('.mrt-ticker');
                balEl.childNodes[0].textContent = parseFloat(data.balance).toFixed(2) + ' ';
            }
            // Actualizar contador de eventos
            var evBadge = document.querySelector('.fa-history')
                              ?.closest('.card-header')
                              ?.querySelector('.badge');
            if (evBadge && data.total_events !== undefined) {
                evBadge.textContent = data.total_events;
            }
        },

        marketplace: function (data) {
            var balEl = document.getElementById('mrt-marketplace-balance');
            if (balEl && data.balance !== undefined) {
                balEl.textContent = parseFloat(data.balance).toFixed(2);
            }
        },

        rewards: function (data) {
            // Puedes ampliar para actualizar tabla de redenciones
            console.log('[MeritCoin] rewards updated', data);
        }
    };

    function poll(page) {
        var handler = handlers[page];
        if (!handler) return;

        fetch(BASE_URL + '?page=' + page, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.error) handler(data);
        })
        .catch(function (err) {
            console.warn('[MeritCoin] poll error:', err);
        });
    }

    // Iniciar polling para la página indicada
    window.MeritCoinPoll = {
        start: function (page, interval, params) {
            var ms = interval || POLL_INTERVAL;
            var query = '?page=' + page;
            if (params) {
                Object.keys(params).forEach(function(k) {
                    query += '&' + k + '=' + params[k];
                });
            }
            // guardar query para el intervalo
            var run = function() { pollWithQuery(query); };
            run();
            setInterval(run, ms);
        }
    };
})();