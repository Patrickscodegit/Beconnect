@extends('layouts.app')

@section('content')
<div class="schedule-page">
    <div class="page-header">
        <h1>Shipping Schedules</h1>
        <p class="page-description">Find and update shipping schedules for your Robaws offers</p>
        
        <div class="sync-status">
            <div class="sync-info">
                <span class="last-sync">Last updated: <strong id="last-sync-time">{{ $lastSyncTime }}</strong></span>
                <button id="sync-button" class="btn btn-primary {{ $isSyncRunning ? 'disabled' : '' }}" 
                        {{ $isSyncRunning ? 'disabled' : '' }}>
                    <i class="fas fa-sync-alt" id="sync-icon"></i>
                    <span id="sync-text">{{ $isSyncRunning ? 'Syncing...' : 'Sync Now' }}</span>
                </button>
            </div>
            <div id="sync-status" class="sync-status-message"></div>
        </div>
    </div>
    
    <div class="schedule-filters">
        <div class="filter-row">
            <div class="filter-group">
                <label for="pol">Port of Loading (POL):</label>
                <select name="pol" id="pol" class="form-select">
                    <option value="">Select POL</option>
                    @foreach($polPorts as $port)
                        <option value="{{ $port->code }}" {{ $pol == $port->code ? 'selected' : '' }}>
                            {{ $port->name }}, {{ $port->country }} ({{ $port->code }})
                        </option>
                    @endforeach
                </select>
            </div>
            
            <div class="filter-group">
                <label for="pod">Port of Discharge (POD):</label>
                <select name="pod" id="pod" class="form-select">
                    <option value="">Select POD</option>
                    @if($podPorts->isEmpty())
                        <option value="" disabled>No PODs yet - add carriers first</option>
                    @else
                        @foreach($podPorts as $port)
                            <option value="{{ $port->code }}" {{ $pod == $port->code ? 'selected' : '' }}>
                                {{ $port->name }}, {{ $port->country }} ({{ $port->code }})
                            </option>
                        @endforeach
                    @endif
                </select>
            </div>
            
            <div class="filter-group">
                <label for="service_type">Service Type:</label>
                <select name="service_type" id="service_type" class="form-select">
                    <option value="">All Service Types</option>
                    <option value="RORO" {{ $serviceType == 'RORO' ? 'selected' : '' }}>RORO</option>
                    <option value="FCL" {{ $serviceType == 'FCL' ? 'selected' : '' }}>FCL</option>
                    <option value="LCL" {{ $serviceType == 'LCL' ? 'selected' : '' }}>LCL</option>
                    <option value="BREAKBULK" {{ $serviceType == 'BREAKBULK' ? 'selected' : '' }}>BREAKBULK</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="offer_id">Robaws Offer ID (Optional):</label>
                <input type="text" name="offer_id" id="offer_id" value="{{ $offerId }}" placeholder="e.g., 12345" class="form-control">
                <small class="help-text">Leave empty to just view schedules</small>
            </div>
            
            <div class="filter-group">
                <button id="search-schedules" class="btn btn-primary">Search Schedules</button>
            </div>
        </div>
    </div>
    
    <div class="schedule-results">
        <div class="loading-indicator" id="loading-indicator" style="display: none;">
            <div class="spinner"></div>
            <span>Searching schedules...</span>
        </div>
        
        <div class="no-results" id="no-results" style="display: none;">
            <p>No schedules found for the selected criteria.</p>
        </div>
        
        <div class="schedule-results-grid" id="schedule-results-grid">
            <!-- Dynamic content loaded via AJAX -->
        </div>
    </div>
</div>

<style>
.schedule-page {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    margin-bottom: 30px;
    text-align: center;
}

.page-header h1 {
    color: #2c3e50;
    margin-bottom: 10px;
}

.page-description {
    color: #7f8c8d;
    font-size: 16px;
    margin-bottom: 20px;
}

.sync-status {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 15px;
    margin-top: 20px;
    text-align: left;
}

.sync-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.last-sync {
    color: #6c757d;
    font-size: 14px;
}

.sync-status-message {
    margin-top: 10px;
}

.sync-status-message .alert {
    margin-bottom: 0;
    padding: 10px 15px;
    border-radius: 4px;
}

.btn.disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.fa-spin {
    animation: fa-spin 1s infinite linear;
}

@keyframes fa-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.schedule-filters {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 30px;
}

.filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    align-items: end;
}

.filter-group {
    display: flex;
    flex-direction: column;
}

.filter-group label {
    font-weight: 600;
    margin-bottom: 5px;
    color: #2c3e50;
}

.form-select, .form-control {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.help-text {
    color: #6c757d;
    font-size: 12px;
    margin-top: 5px;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
}

.btn-primary {
    background-color: #007bff;
    color: white;
}

.btn-primary:hover {
    background-color: #0056b3;
}

.btn-secondary {
    background-color: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background-color: #545b62;
}

.btn-sm {
    padding: 5px 10px;
    font-size: 12px;
}

.loading-indicator {
    text-align: center;
    padding: 40px;
}

.spinner {
    border: 4px solid #f3f3f3;
    border-top: 4px solid #007bff;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    animation: spin 1s linear infinite;
    margin: 0 auto 20px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.schedule-results-grid {
    display: grid;
    gap: 20px;
}

.carrier-group {
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 20px;
}

.carrier-group h3 {
    color: #2c3e50;
    margin-bottom: 10px;
    border-bottom: 2px solid #007bff;
    padding-bottom: 10px;
}

.carrier-info {
    margin-bottom: 20px;
    font-size: 14px;
    color: #6c757d;
}

.specialization, .service-types {
    display: inline-block;
    background: #e9ecef;
    padding: 4px 8px;
    border-radius: 4px;
    margin-right: 10px;
    margin-bottom: 5px;
}

.schedules-list {
    display: grid;
    gap: 15px;
}

.schedule-card {
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 15px;
    background: #f8f9fa;
}

.schedule-navigation {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding: 10px 0;
    border-bottom: 1px solid #dee2e6;
}

.schedule-counter {
    font-weight: 500;
    color: #495057;
}

.schedule-header {
    display: flex;
    justify-content: between;
    align-items: center;
    margin-bottom: 15px;
}

.schedule-header h4 {
    color: #2c3e50;
    margin: 0;
    flex: 1;
}

.frequency {
    background: #007bff;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}

.schedule-details {
    margin-bottom: 15px;
}

.schedule-info-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
}

.info-section {
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-width: 0; /* Prevents overflow */
}

.detail-row {
    display: flex;
    flex-direction: column;
}

.detail-row .label {
    font-weight: 600;
    color: #495057;
    font-size: 12px;
    margin-bottom: 2px;
}

.detail-row .value {
    color: #2c3e50;
    font-size: 14px;
}

.schedule-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.no-results {
    text-align: center;
    padding: 40px;
    color: #6c757d;
}

@media (max-width: 768px) {
    .filter-row {
        grid-template-columns: 1fr;
    }
    
    .schedule-info-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }
    
    @media (max-width: 480px) {
        .schedule-info-grid {
            grid-template-columns: 1fr;
            gap: 15px;
        }
    }
    
    .schedule-actions {
        flex-direction: column;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchButton = document.getElementById('search-schedules');
    const loadingIndicator = document.getElementById('loading-indicator');
    const noResults = document.getElementById('no-results');
    const resultsGrid = document.getElementById('schedule-results-grid');
    
    // Search schedules
    searchButton.addEventListener('click', function() {
        const pol = document.getElementById('pol').value;
        const pod = document.getElementById('pod').value;
        const serviceType = document.getElementById('service_type').value;
        const offerId = document.getElementById('offer_id').value;
        
        if (!pol || !pod) {
            alert('Please select both POL and POD');
            return;
        }
        
        // Show loading
        loadingIndicator.style.display = 'block';
        noResults.style.display = 'none';
        resultsGrid.innerHTML = '';
        
        fetch(`/schedules/search?pol=${pol}&pod=${pod}&service_type=${serviceType}&offer_id=${offerId}`)
            .then(response => response.json())
            .then(data => {
                loadingIndicator.style.display = 'none';
                displayScheduleResults(data, offerId);
            })
            .catch(error => {
                loadingIndicator.style.display = 'none';
                console.error('Error:', error);
                alert('Error searching schedules: ' + error.message);
            });
    });
    
    // Format date for display
    function formatDate(dateString) {
        if (!dateString) return 'TBA';
        try {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        } catch (e) {
            return dateString;
        }
    }

    // Global variables for schedule navigation
    let allSchedules = [];
    let currentScheduleIndex = 0;
    let currentOfferId = null;

    // Display schedule results
    function displayScheduleResults(data, offerId) {
        // Store offerId globally for use in navigation
        currentOfferId = offerId;
        
        if (data.carriers && Object.keys(data.carriers).length > 0) {
            // Flatten all schedules into a single array for navigation
            allSchedules = [];
            Object.values(data.carriers).forEach(carrier => {
                carrier.schedules.forEach(schedule => {
                    allSchedules.push({
                        ...schedule,
                        carrier: carrier
                    });
                });
            });
            
            // Schedules are already sorted chronologically by backend (oldest to latest)
            // No need to re-sort - just find the schedule closest to today for initial view
            
            // Find the schedule with ETS closest to today's date
            currentScheduleIndex = findClosestScheduleIndex(allSchedules);
            displayCurrentSchedule();
        } else {
            resultsGrid.innerHTML = '<div class="no-results"><p>No schedules found for the selected criteria.</p></div>';
        }
    }
    
    // Find the schedule index with ETS closest to today's date
    function findClosestScheduleIndex(schedules) {
        if (schedules.length === 0) return 0;
        
        const today = new Date();
        today.setHours(0, 0, 0, 0); // Normalize to start of day
        
        let closestIndex = 0;
        let smallestDiff = Infinity;
        
        schedules.forEach((schedule, index) => {
            const etsDate = new Date(schedule.ets_pol);
            etsDate.setHours(0, 0, 0, 0); // Normalize to start of day
            
            const diff = Math.abs(etsDate - today);
            
            if (diff < smallestDiff) {
                smallestDiff = diff;
                closestIndex = index;
            }
        });
        
        return closestIndex;
    }
    
    // Display the current schedule with navigation
    function displayCurrentSchedule() {
        if (allSchedules.length === 0) {
            resultsGrid.innerHTML = '<div class="no-results"><p>No schedules found for the selected criteria.</p></div>';
            return;
        }
        
        const schedule = allSchedules[currentScheduleIndex];
        const carrier = schedule.carrier;
        
        let html = `
            <div class="schedule-navigation">
                <button class="btn btn-sm btn-secondary" onclick="previousSchedule()" ${currentScheduleIndex === 0 ? 'disabled' : ''}>
                    ← Previous
                </button>
                <span class="schedule-counter">${currentScheduleIndex + 1} of ${allSchedules.length}</span>
                <button class="btn btn-sm btn-secondary" onclick="nextSchedule()" ${currentScheduleIndex === allSchedules.length - 1 ? 'disabled' : ''}>
                    Next →
                </button>
            </div>
            
            <div class="carrier-group">
                <h3>${carrier.name}</h3>
                <div class="carrier-info">
                    <span class="specialization">${carrier.specialization ? (() => {
                        try {
                            const spec = typeof carrier.specialization === 'string' ? JSON.parse(carrier.specialization) : carrier.specialization;
                            return typeof spec === 'object' && spec !== null ? Object.keys(spec).filter(key => spec[key] === true).join(', ') : '';
                        } catch (e) {
                            return '';
                        }
                    })() : ''}</span>
                    <span class="service-types">${carrier.service_types ? (() => {
                        try {
                            const types = typeof carrier.service_types === 'string' ? JSON.parse(carrier.service_types) : carrier.service_types;
                            return Array.isArray(types) ? types.join(', ') : '';
                        } catch (e) {
                            return '';
                        }
                    })() : ''}</span>
                </div>
                
                <div class="schedule-card">
                    <div class="schedule-header">
                        <h4>${schedule.service_name}</h4>
                        <span class="frequency">${schedule.accurate_frequency_display || schedule.frequency_per_month + 'x/month'}</span>
                    </div>
                    
                    <div class="schedule-details">
                        <div class="schedule-info-grid">
                            <div class="info-section">
                                <div class="detail-row">
                                    <span class="label">Transit Time:</span>
                                    <span class="value">${schedule.transit_days} days</span>
                                </div>
                                <div class="detail-row">
                                    <span class="label">Next Sailing:</span>
                                    <span class="value">${formatDate(schedule.next_sailing_date)}</span>
                                </div>
                            </div>
                            
                            <div class="info-section">
                                <div class="detail-row">
                                    <span class="label">Vessel:</span>
                                    <span class="value">${schedule.vessel_name || 'TBA'}${schedule.voyage_number ? ' (' + schedule.voyage_number + ')' : ''}</span>
                                </div>
                                ${schedule.carrier.code === 'SALLAUM' ? `
                                <div class="detail-row">
                                    <span class="label">Departure Terminal:</span>
                                    <span class="value">332</span>
                                </div>
                                ` : ''}
                            </div>
                            
                            <div class="info-section">
                                <div class="detail-row">
                                    <span class="label">POL:</span>
                                    <span class="value">${schedule.pol_port.name}, ${schedule.pol_port.country} (${schedule.pol_port.code})</span>
                                </div>
                                <div class="detail-row">
                                    <span class="label">ETS:</span>
                                    <span class="value">${formatDate(schedule.ets_pol)}</span>
                                </div>
                            </div>
                            
                            <div class="info-section">
                                <div class="detail-row">
                                    <span class="label">POD:</span>
                                    <span class="value">${schedule.pod_port.name}, ${schedule.pod_port.country} (${schedule.pod_port.code})</span>
                                </div>
                                <div class="detail-row">
                                    <span class="label">ETA:</span>
                                    <span class="value">${formatDate(schedule.eta_pod)}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="schedule-actions">
                        <button class="btn btn-sm btn-secondary copy-schedule" 
                                data-schedule='${JSON.stringify(schedule)}'>
                            Copy to Clipboard
                        </button>
                        <button class="btn btn-sm btn-primary update-offer" 
                                data-schedule='${JSON.stringify(schedule)}' 
                                data-offer-id="${currentOfferId || ''}">
                            Update Robaws Offer
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        resultsGrid.innerHTML = html;
    }
    
    // Navigation functions
    function previousSchedule() {
        if (currentScheduleIndex > 0) {
            currentScheduleIndex--;
            displayCurrentSchedule();
        }
    }
    
    function nextSchedule() {
        if (currentScheduleIndex < allSchedules.length - 1) {
            currentScheduleIndex++;
            displayCurrentSchedule();
        }
    }
    
    // Make navigation functions global
    window.previousSchedule = previousSchedule;
    window.nextSchedule = nextSchedule;
    
    // Copy to clipboard
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('copy-schedule')) {
            const schedule = JSON.parse(e.target.dataset.schedule);
            const scheduleText = `
Service: ${schedule.service_name}
Frequency: ${schedule.accurate_frequency_display || schedule.frequency_per_month + 'x/month'}
Transit Time: ${schedule.transit_days} days
Next Sailing: ${formatDate(schedule.next_sailing_date)}
${schedule.carrier.code === 'SALLAUM' ? 'Departure Terminal: 332' : ''}
Vessel: ${schedule.vessel_name || 'TBA'}${schedule.voyage_number ? ' (Voyage ' + schedule.voyage_number + ')' : ''}
POL: ${schedule.pol_port.name}, ${schedule.pol_port.country} (${schedule.pol_port.code})
ETS: ${formatDate(schedule.ets_pol)}
POD: ${schedule.pod_port.name}, ${schedule.pod_port.country} (${schedule.pod_port.code})
ETA: ${formatDate(schedule.eta_pod)}
            `.trim();
            
            navigator.clipboard.writeText(scheduleText).then(function() {
                alert('Schedule copied to clipboard!');
            });
        }
    });
    
    // Update offer
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('update-offer')) {
            const schedule = JSON.parse(e.target.dataset.schedule);
            const offerId = e.target.dataset.offerId;
            
            if (!offerId || offerId === 'null') {
                alert('Please enter a Robaws Offer ID in the filter section.');
                return;
            }
            
            if (!confirm(`Update Robaws Offer ${offerId} with schedule "${schedule.service_name}"?`)) {
                return;
            }
            
            fetch('/schedules/update-offer', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    offer_id: offerId,
                    schedule_id: schedule.id
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`Offer ${offerId} updated successfully with schedule "${data.schedule}"`);
                } else {
                    alert('Error updating offer: ' + data.error);
                }
            })
            .catch(error => {
                alert('Error updating offer: ' + error.message);
            });
        }
    });
    
    // Sync functionality
    document.getElementById('sync-button').addEventListener('click', function() {
        const button = this;
        const icon = document.getElementById('sync-icon');
        const text = document.getElementById('sync-text');
        const statusDiv = document.getElementById('sync-status');
        
        // Disable button and show loading state
        button.disabled = true;
        icon.classList.add('fa-spin');
        text.textContent = 'Syncing...';
        statusDiv.innerHTML = '<div class="alert alert-info">Starting sync...</div>';
        
        fetch('/schedules/sync', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                statusDiv.innerHTML = '<div class="alert alert-success">Sync started successfully! This may take a few minutes.</div>';
                
                // Start polling for status updates
                pollSyncStatus(data.syncLogId);
            } else {
                statusDiv.innerHTML = `<div class="alert alert-danger">Error: ${data.message}</div>`;
                resetSyncButton();
            }
        })
        .catch(error => {
            statusDiv.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
            resetSyncButton();
        });
    });
    
    function pollSyncStatus(syncLogId) {
        const statusDiv = document.getElementById('sync-status');
        const lastSyncTime = document.getElementById('last-sync-time');
        
        // Set a timeout to prevent infinite polling (10 minutes max)
        const maxPollingTime = 10 * 60 * 1000; // 10 minutes
        const startTime = Date.now();
        
        const pollInterval = setInterval(() => {
            // Check if we've been polling too long
            if (Date.now() - startTime > maxPollingTime) {
                clearInterval(pollInterval);
                statusDiv.innerHTML = `
                    <div class="alert alert-warning">
                        Sync is taking longer than expected. 
                        <button onclick="resetStuckSync()" class="btn btn-sm btn-outline-warning ms-2">
                            Reset Sync
                        </button>
                    </div>
                `;
                resetSyncButton();
                return;
            }
            
            fetch('/schedules/sync-status')
            .then(response => response.json())
            .then(data => {
                if (data.latestSync && data.latestSync.id === syncLogId) {
                    if (data.latestSync.status === 'success') {
                        clearInterval(pollInterval);
                        statusDiv.innerHTML = `
                            <div class="alert alert-success">
                                Sync completed successfully! 
                                Updated ${data.latestSync.schedules_updated} schedules from ${data.latestSync.carriers_processed} carriers.
                                Duration: ${data.latestSync.duration}
                            </div>
                        `;
                        lastSyncTime.textContent = data.latestSync.completed_at;
                        resetSyncButton();
                    } else if (data.latestSync.status === 'error') {
                        clearInterval(pollInterval);
                        statusDiv.innerHTML = `
                            <div class="alert alert-danger">
                                Sync failed: ${data.latestSync.error_message || 'Unknown error'}. 
                                <button onclick="resetStuckSync()" class="btn btn-sm btn-outline-danger ms-2">
                                    Reset Sync
                                </button>
                            </div>
                        `;
                        resetSyncButton();
                    }
                }
                
                // Update sync running status
                if (!data.isSyncRunning) {
                    clearInterval(pollInterval);
                    resetSyncButton();
                }
            })
            .catch(error => {
                console.error('Error polling sync status:', error);
                clearInterval(pollInterval);
                statusDiv.innerHTML = `
                    <div class="alert alert-danger">
                        Error checking sync status: ${error.message}. 
                        <button onclick="resetStuckSync()" class="btn btn-sm btn-outline-danger ms-2">
                            Reset Sync
                        </button>
                    </div>
                `;
                resetSyncButton();
            });
        }, 5000); // Poll every 5 seconds
    }
    
    // Function to reset stuck sync
    function resetStuckSync() {
        if (confirm('This will reset any stuck sync operations. Continue?')) {
            fetch('/schedules/sync-status', {
                method: 'DELETE', // We'll add this endpoint
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Content-Type': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('sync-status').innerHTML = 
                        '<div class="alert alert-info">Sync reset successfully. You can now start a new sync.</div>';
                    resetSyncButton();
                } else {
                    alert('Failed to reset sync: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error resetting sync:', error);
                alert('Error resetting sync: ' + error.message);
            });
        }
    }
    
    function resetSyncButton() {
        const button = document.getElementById('sync-button');
        const icon = document.getElementById('sync-icon');
        const text = document.getElementById('sync-text');
        
        button.disabled = false;
        icon.classList.remove('fa-spin');
        text.textContent = 'Sync Now';
    }
    
    // Auto-refresh sync status every 30 seconds
    setInterval(() => {
        fetch('/schedules/sync-status')
        .then(response => response.json())
        .then(data => {
            document.getElementById('last-sync-time').textContent = data.lastSyncTime;
            
            const button = document.getElementById('sync-button');
            const icon = document.getElementById('sync-icon');
            const text = document.getElementById('sync-text');
            
            if (data.isSyncRunning) {
                button.disabled = true;
                icon.classList.add('fa-spin');
                text.textContent = 'Syncing...';
            } else {
                button.disabled = false;
                icon.classList.remove('fa-spin');
                text.textContent = 'Sync Now';
            }
        })
        .catch(error => {
            console.error('Error refreshing sync status:', error);
        });
    }, 30000);
});
</script>
@endsection
