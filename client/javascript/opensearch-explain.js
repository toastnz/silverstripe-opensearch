(function () {
    var selector = '[data-opensearch-explain]';

    var clear = function (element) {
        while (element.firstChild) {
            element.removeChild(element.firstChild);
        }
    };

    var showOutput = function (output) {
        output.hidden = false;
    };

    var hideOutput = function (output) {
        clear(output);
        output.hidden = true;
    };

    var appendText = function (parent, tag, className, text) {
        var element = document.createElement(tag);

        if (className) {
            element.className = className;
        }

        element.textContent = text;
        parent.appendChild(element);

        return element;
    };

    var appendLine = function (parent, line) {
        var item = document.createElement('li');

        if (!line || !Array.isArray(line.parts)) {
            item.textContent = line || '';
            parent.appendChild(item);
            return item;
        }

        line.parts.forEach(function (part) {
            var span = document.createElement('span');

            span.textContent = part.text || '';

            if (part.style === 'field') {
                span.className = 'opensearch-explain-field-name';
            } else if (part.style === 'term') {
                span.className = 'opensearch-explain-term';
            } else if (part.style === 'score') {
                span.className = 'opensearch-explain-score-value';
            }

            item.appendChild(span);
        });

        parent.appendChild(item);
        return item;
    };

    var formatScore = function (score) {
        var number = Number(score);

        if (Number.isNaN(number)) {
            return null;
        }

        return number.toFixed(4).replace(/\.?0+$/, '');
    };

    var showResponse = function (output, data) {
        var summary;
        var list;

        clear(output);
        showOutput(output);

        if (!data || !Array.isArray(data.results)) {
            output.textContent = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
            return;
        }

        summary = appendText(
            output,
            'p',
            'opensearch-explain-summary',
            'Showing ' + data.returned + ' of ' + data.total + ' result' + (data.total === 1 ? '' : 's') + (data.took !== null && data.took !== undefined ? ' in ' + data.took + 'ms.' : '.')
        );

        if (!data.results.length) {
            summary.textContent = 'No results found.';
            return;
        }

        list = document.createElement('ol');
        list.className = 'opensearch-explain-results';
        output.appendChild(list);

        data.results.forEach(function (result) {
            var item = document.createElement('li');
            var heading = document.createElement('div');
            var title = appendText(heading, 'span', 'opensearch-explain-title', result.title || 'Untitled result');
            var explanation = document.createElement('ul');
            var calculation = document.createElement('div');
            var calculationLines = document.createElement('ul');

            item.className = 'opensearch-explain-result';
            heading.className = 'opensearch-explain-heading';

            if (result.link) {
                title = document.createElement('a');
                title.className = 'opensearch-explain-title';
                title.textContent = result.title || result.link;
                title.href = result.link;
                title.target = '_blank';
                title.rel = 'noopener noreferrer';
                heading.textContent = '';
                heading.appendChild(title);
            }

            if (result.score !== null && result.score !== undefined) {
                var score = formatScore(result.score);

                if (score !== null) {
                    appendText(heading, 'span', 'opensearch-explain-score', 'Score ' + score);
                }
            }

            item.appendChild(heading);

            explanation.className = 'opensearch-explain-lines';
            (result.explanation || []).forEach(function (line) {
                appendLine(explanation, line);
            });
            item.appendChild(explanation);

            if (Array.isArray(result.summary) && result.summary.length) {
                calculation.className = 'opensearch-explain-calculation';
                appendText(calculation, 'h4', 'opensearch-explain-calculation-title', 'How this was calculated');
                calculationLines.className = 'opensearch-explain-calculation-lines';

                result.summary.forEach(function (line) {
                    appendLine(calculationLines, line);
                });

                calculation.appendChild(calculationLines);
                item.appendChild(calculation);
            }

            list.appendChild(item);
        });
    };

    var showError = function (output, message) {
        clear(output);
        showOutput(output);
        output.textContent = message || 'OpenSearch explain failed.';
    };

    var explain = function (holder, input, limitInput, button, output) {
        var endpoint = holder.getAttribute('data-endpoint') || '';
        var searchTerm = input.value.trim();
        var limit = limitInput ? limitInput.value : '25';
        var defaultButtonText = button.getAttribute('data-default-text') || button.textContent || 'Search';

        if (!endpoint) {
            showError(output, 'OpenSearch explain is unavailable.');
            return;
        }

        if (!searchTerm) {
            showError(output, 'Enter a search term to explain.');
            return;
        }

        button.disabled = true;
        button.textContent = 'Searching...';
        showOutput(output);
        clear(output);
        output.textContent = 'Searching...';

        fetch(endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'Search=' + encodeURIComponent(searchTerm) + '&Limit=' + encodeURIComponent(limit), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        }).then(function (response) {
            return response.text().then(function (text) {
                var data = text;

                try {
                    data = JSON.parse(text);
                } catch (e) {
                    data = text;
                }

                if (!response.ok) {
                    throw new Error(data && data.error ? data.error : response.statusText);
                }

                return data;
            });
        }).then(function (data) {
            showResponse(output, data);
        }).catch(function (error) {
            showError(output, error.message);
        }).finally(function () {
            button.disabled = false;
            button.textContent = defaultButtonText;
        });
    };

    var initialiseHolder = function (holder) {
        if (!holder || holder.getAttribute('data-opensearch-explain-ready') === '1') {
            return;
        }

        var input = holder.querySelector('#OpenSearchExplainSearchInput');
        var limitInput = holder.querySelector('#OpenSearchExplainLimitInput');
        var button = holder.querySelector('[data-opensearch-explain-button]');
        var output = holder.querySelector('[data-opensearch-explain-output]');

        if (!input || !button || !output) {
            return;
        }

        holder.setAttribute('data-opensearch-explain-ready', '1');
        button.setAttribute('data-default-text', button.textContent || 'Search');
        hideOutput(output);

        button.addEventListener('click', function () {
            explain(holder, input, limitInput, button, output);
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                explain(holder, input, limitInput, button, output);
            }
        });
    };

    var initialise = function (root) {
        var context = root && root.querySelectorAll ? root : document;
        var holders = [];

        if (context.matches && context.matches(selector)) {
            holders.push(context);
        }

        Array.prototype.forEach.call(context.querySelectorAll(selector), function (holder) {
            holders.push(holder);
        });

        holders.forEach(initialiseHolder);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initialise(document);
        });
    } else {
        initialise(document);
    }

    if (window.jQuery) {
        window.jQuery(document).on('ajaxComplete', function () {
            initialise(document);
        });
    }

    if ('MutationObserver' in window) {
        new MutationObserver(function (records) {
            records.forEach(function (record) {
                Array.prototype.forEach.call(record.addedNodes, function (node) {
                    if (node.nodeType === 1) {
                        initialise(node);
                    }
                });
            });
        }).observe(document.documentElement, {
            childList: true,
            subtree: true
        });
    }
}());
