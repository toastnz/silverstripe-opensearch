<div class="opensearch-explain-field" data-opensearch-explain data-endpoint="$Endpoint.ATT">
    <div class="opensearch-explain-control">
        <div class="field text opensearch-explain-input">
            <label class="left" for="OpenSearchExplainSearchInput">Search and Explain</label>
            <div class="middleColumn">
                <input type="text" class="text" id="OpenSearchExplainSearchInput" autocomplete="off">
            </div>
            <label class="left" for="OpenSearchExplainLimitInput">Results to show</label>
            <div class="middleColumn">
                <select class="dropdown" id="OpenSearchExplainLimitInput">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>
        <button type="button" class="btn btn-primary font-icon-search" data-opensearch-explain-button>Search</button>
    </div>
    <div class="opensearch-explain-output" data-opensearch-explain-output aria-live="polite" hidden></div>
</div>
