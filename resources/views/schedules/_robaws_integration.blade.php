{{-- Robaws Article Selection Widget --}}
{{-- This partial can be included in schedules/index.blade.php or loaded via AJAX --}}
{{-- Completely separate from existing schedule functionality --}}

<div class="robaws-integration-widget" id="robaws-integration-widget">
    <div class="widget-header">
        <h5>🔗 Robaws Offer Integration</h5>
        <p class="text-muted">Link this schedule to a Robaws offer and select appropriate articles</p>
    </div>

    <div class="offer-input-section">
        <div class="form-group">
            <label for="robaws-offer-id">Robaws Offer ID:</label>
            <input type="text" 
                   id="robaws-offer-id" 
                   class="form-control" 
                   placeholder="Enter Robaws Offer ID (e.g., 12345)">
            <small class="form-text text-muted">
                Enter the Robaws offer ID you want to update with this schedule's details
            </small>
        </div>

        <div class="form-group">
            <label for="service-type-select">Service Type:</label>
            <select id="service-type-select" class="form-select">
                <option value="">Select service type...</option>
                @foreach(config('quotation.service_types', []) as $code => $name)
                    <option value="{{ $code }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <button type="button" 
                class="btn btn-primary" 
                id="suggest-articles-btn"
                onclick="robawsSuggestArticles()">
            <i class="fas fa-lightbulb"></i> Suggest Articles
        </button>
    </div>

    <div id="suggested-articles-section" style="display: none;">
        <hr>
        <h6>Suggested Robaws Articles</h6>
        <p class="text-muted">Based on carrier, service type, and cargo details</p>

        <div id="article-suggestions-container">
            {{-- Articles will be loaded here dynamically --}}
        </div>

        <div class="action-buttons mt-3">
            <button type="button" 
                    class="btn btn-success" 
                    id="update-offer-btn"
                    onclick="robawsUpdateOfferWithArticles()">
                <i class="fas fa-check"></i> Update Offer in Robaws
            </button>
            <button type="button" 
                    class="btn btn-secondary" 
                    onclick="robawsCancelArticleSelection()">
                Cancel
            </button>
        </div>
    </div>

    <div id="robaws-integration-status" class="mt-3"></div>
</div>

<style>
.robaws-integration-widget {
    background: #f8f9fa;
    border: 2px solid #dee2e6;
    border-radius: 8px;
    padding: 20px;
    margin-top: 20px;
}

.widget-header h5 {
    color: #495057;
    margin-bottom: 5px;
}

.offer-input-section {
    background: white;
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 15px;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    font-weight: 600;
    color: #495057;
    margin-bottom: 5px;
}

#article-suggestions-container {
    background: white;
    padding: 15px;
    border-radius: 6px;
    max-height: 400px;
    overflow-y: auto;
}

.article-category {
    margin-bottom: 20px;
}

.article-category h6 {
    color: #007bff;
    border-bottom: 2px solid #007bff;
    padding-bottom: 5px;
    margin-bottom: 10px;
}

.article-item {
    padding: 10px;
    border: 1px solid #e9ecef;
    border-radius: 4px;
    margin-bottom: 8px;
    background: #f8f9fa;
}

.article-item:hover {
    background: #e9ecef;
}

.article-item label {
    margin: 0;
    cursor: pointer;
    width: 100%;
}

.article-name {
    font-weight: 600;
    color: #2c3e50;
}

.article-code {
    color: #6c757d;
    font-size: 0.9em;
}

.article-price {
    color: #28a745;
    font-weight: 600;
}

.article-reason {
    color: #856404;
    background: #fff3cd;
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 0.85em;
    margin-left: 10px;
}

.badge-mandatory {
    background: #dc3545;
    color: white;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 0.85em;
    margin-left: 5px;
}

.badge-recommended {
    background: #ffc107;
    color: #000;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 0.85em;
    margin-left: 5px;
}

.action-buttons {
    display: flex;
    gap: 10px;
}

.action-buttons button {
    flex: 1;
}

.alert {
    padding: 12px 15px;
    border-radius: 4px;
    margin-bottom: 0;
}

.alert-success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

.alert-danger {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

.alert-info {
    background: #d1ecf1;
    border: 1px solid #bee5eb;
    color: #0c5460;
}
</style>

<script>
// Global variables for article selection
let selectedArticles = [];
let currentScheduleId = null;

/**
 * Suggest articles based on schedule and service type
 */
function robawsSuggestArticles() {
    const offerId = document.getElementById('robaws-offer-id').value;
    const serviceType = document.getElementById('service-type-select').value;
    const statusDiv = document.getElementById('robaws-integration-status');
    
    if (!offerId) {
        statusDiv.innerHTML = '<div class="alert alert-danger">Please enter a Robaws Offer ID</div>';
        return;
    }
    
    if (!serviceType) {
        statusDiv.innerHTML = '<div class="alert alert-danger">Please select a service type</div>';
        return;
    }
    
    // Get current schedule ID (assumes it's available from parent page)
    currentScheduleId = getCurrentScheduleId();
    
    if (!currentScheduleId) {
        statusDiv.innerHTML = '<div class="alert alert-danger">No schedule selected. Please select a schedule first.</div>';
        return;
    }
    
    statusDiv.innerHTML = '<div class="alert alert-info">Loading article suggestions...</div>';
    document.getElementById('suggest-articles-btn').disabled = true;
    
    fetch('/robaws/schedule/suggest-articles', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
            schedule_id: currentScheduleId,
            offer_id: offerId,
            service_type: serviceType,
            cargo_details: {} // Can be enhanced with actual cargo details
        })
    })
    .then(response => response.json())
    .then(data => {
        document.getElementById('suggest-articles-btn').disabled = false;
        
        if (data.success) {
            displayArticleSuggestions(data.suggestions);
            statusDiv.innerHTML = '<div class="alert alert-success">Found ' + data.total_articles + ' applicable articles</div>';
            document.getElementById('suggested-articles-section').style.display = 'block';
        } else {
            statusDiv.innerHTML = '<div class="alert alert-danger">Error: ' + data.error + '</div>';
        }
    })
    .catch(error => {
        document.getElementById('suggest-articles-btn').disabled = false;
        statusDiv.innerHTML = '<div class="alert alert-danger">Error: ' + error.message + '</div>';
    });
}

/**
 * Display article suggestions grouped by type
 */
function displayArticleSuggestions(suggestions) {
    const container = document.getElementById('article-suggestions-container');
    let html = '';
    
    selectedArticles = []; // Reset
    
    // Mandatory articles
    if (suggestions.mandatory && suggestions.mandatory.length > 0) {
        html += '<div class="article-category"><h6>Mandatory Articles</h6>';
        suggestions.mandatory.forEach(article => {
            html += renderArticleItem(article, true, true);
            selectedArticles.push(article.robaws_article_id); // Auto-select mandatory
        });
        html += '</div>';
    }
    
    // Recommended articles
    if (suggestions.recommended && suggestions.recommended.length > 0) {
        html += '<div class="article-category"><h6>Recommended Articles</h6>';
        suggestions.recommended.forEach(article => {
            html += renderArticleItem(article, true, false);
            selectedArticles.push(article.robaws_article_id); // Auto-select recommended
        });
        html += '</div>';
    }
    
    // Optional articles
    if (suggestions.optional && suggestions.optional.length > 0) {
        html += '<div class="article-category"><h6>Optional Articles</h6>';
        suggestions.optional.forEach(article => {
            html += renderArticleItem(article, false, false);
        });
        html += '</div>';
    }
    
    container.innerHTML = html;
}

/**
 * Render single article item
 */
function renderArticleItem(article, checked, disabled) {
    const isMandatory = disabled;
    const badge = isMandatory 
        ? '<span class="badge-mandatory">MANDATORY</span>' 
        : (checked ? '<span class="badge-recommended">RECOMMENDED</span>' : '');
    
    const reason = article.reason ? `<span class="article-reason">${article.reason}</span>` : '';
    
    return `
        <div class="article-item">
            <label>
                <input type="checkbox" 
                       value="${article.robaws_article_id}" 
                       ${checked ? 'checked' : ''} 
                       ${disabled ? 'disabled' : ''}
                       onchange="robawsToggleArticle(this)">
                <span class="article-name">${article.article_name}</span>
                ${badge}
                <br>
                <small class="article-code">Code: ${article.article_code}</small>
                ${article.unit_price ? ' - <span class="article-price">' + article.currency + ' ' + article.unit_price + '</span>' : ''}
                ${reason}
            </label>
        </div>
    `;
}

/**
 * Toggle article selection
 */
function robawsToggleArticle(checkbox) {
    const articleId = checkbox.value;
    
    if (checkbox.checked) {
        if (!selectedArticles.includes(articleId)) {
            selectedArticles.push(articleId);
        }
    } else {
        selectedArticles = selectedArticles.filter(id => id !== articleId);
    }
}

/**
 * Update Robaws offer with selected articles
 */
function robawsUpdateOfferWithArticles() {
    const offerId = document.getElementById('robaws-offer-id').value;
    const statusDiv = document.getElementById('robaws-integration-status');
    
    if (selectedArticles.length === 0) {
        statusDiv.innerHTML = '<div class="alert alert-danger">Please select at least one article</div>';
        return;
    }
    
    if (!confirm(`Update Robaws Offer ${offerId} with ${selectedArticles.length} selected articles?`)) {
        return;
    }
    
    statusDiv.innerHTML = '<div class="alert alert-info">Updating offer in Robaws...</div>';
    document.getElementById('update-offer-btn').disabled = true;
    
    fetch('/robaws/schedule/update-articles', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
            offer_id: offerId,
            schedule_id: currentScheduleId,
            article_ids: selectedArticles
        })
    })
    .then(response => response.json())
    .then(data => {
        document.getElementById('update-offer-btn').disabled = false;
        
        if (data.success) {
            statusDiv.innerHTML = `<div class="alert alert-success">
                ✓ Offer updated successfully in Robaws!<br>
                ${data.articles_added} articles added to offer ${data.offer_id}
            </div>`;
            
            // Reset form after success
            setTimeout(() => {
                robawsCancelArticleSelection();
            }, 3000);
        } else {
            statusDiv.innerHTML = '<div class="alert alert-danger">Error: ' + data.error + '</div>';
        }
    })
    .catch(error => {
        document.getElementById('update-offer-btn').disabled = false;
        statusDiv.innerHTML = '<div class="alert alert-danger">Error: ' + error.message + '</div>';
    });
}

/**
 * Cancel article selection
 */
function robawsCancelArticleSelection() {
    document.getElementById('suggested-articles-section').style.display = 'none';
    document.getElementById('robaws-offer-id').value = '';
    document.getElementById('service-type-select').value = '';
    document.getElementById('robaws-integration-status').innerHTML = '';
    selectedArticles = [];
}

/**
 * Helper function to get current schedule ID
 * This should be provided by the parent page (schedules/index.blade.php)
 */
function getCurrentScheduleId() {
    // Try to get from global variable first
    if (typeof currentScheduleIndex !== 'undefined' && typeof allSchedules !== 'undefined') {
        return allSchedules[currentScheduleIndex]?.id;
    }
    
    // Fallback: try to find in DOM
    const activeSchedule = document.querySelector('.schedule-card.active');
    if (activeSchedule) {
        return activeSchedule.dataset.scheduleId;
    }
    
    return null;
}
</script>

<style scoped>
.robaws-integration-widget {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    color: white;
}

.widget-header h5 {
    color: white;
    margin-bottom: 5px;
}

.widget-header .text-muted {
    color: rgba(255,255,255,0.8) !important;
    font-size: 0.9em;
}

.offer-input-section {
    background: white;
    color: #333;
}
</style>

