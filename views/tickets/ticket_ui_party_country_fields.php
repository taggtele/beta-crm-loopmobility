<?php
/**
 * Ticket form UI fragment: party search combobox + country datalist (create / edit / modal).
 *
 * @var string $ticketCountryFieldNs
 * @var string $ticketPartySearchUrl
 * @var array<int|string, string> $ticketCountryDropdownOptions
 * @var int|string $selectedCustomerPartyId
 * @var string $selectedCustomerDisplay
 * @var string $selectedCountry
 * @var bool $ticketCountryShowRequired
 */
$ns = preg_replace('/[^a-z0-9_-]/i', '', (string) $ticketCountryFieldNs);
if ($ns === '') {
    $ns = 'ticket';
}
$reqHtml = !empty($ticketCountryShowRequired) ? ' <span class="req">*</span>' : '';
$countryDatalistId = $ns . '_country_datalist';
?>        <div class="input-group ticket-party-combo-group">
            <label for="<?php echo e($ns); ?>_customer_visible">Customer<?php echo $reqHtml; ?></label>
            <div
                class="ticket-party-combobox"
                data-party-combo-wrap
                data-party-search-url="<?php echo e($ticketPartySearchUrl); ?>"
            >
                <input
                    type="hidden"
                    name="customer_party_id"
                    id="<?php echo e($ns); ?>_customer_party_id"
                    data-party-combo-id
                    value="<?php echo e((string) (int) $selectedCustomerPartyId); ?>"
                >
                <div class="ticket-party-combobox-input-row">
                    <input
                        type="text"
                        id="<?php echo e($ns); ?>_customer_visible"
                        name="customer"
                        value="<?php echo e($selectedCustomerDisplay); ?>"
                        autocomplete="off"
                        required
                        maxlength="190"
                        data-party-combo-input
                        data-selected-party-label="<?php echo e($selectedCustomerDisplay); ?>"
                        placeholder="Search active parties…"
                        aria-autocomplete="list"
                        aria-controls="<?php echo e($ns); ?>_party_results"
                        aria-expanded="false"
                    >
                    <ul
                        class="ticket-party-combobox-results"
                        id="<?php echo e($ns); ?>_party_results"
                        data-party-combo-results
                        hidden
                        role="listbox"
                    ></ul>
                </div>
            </div>
            <div class="field-help">Pick a customer from registered active parties (type to search). Free text alone is not accepted on save.</div>
        </div>

        <div class="input-group<?php echo $ns === 'modal' ? '' : ''; ?>">
            <label for="<?php echo e($ns); ?>_country">Country<?php echo $reqHtml; ?></label>
            <input
                type="text"
                id="<?php echo e($ns); ?>_country"
                name="country"
                value="<?php echo e($selectedCountry); ?>"
                required
                maxlength="120"
                list="<?php echo e($countryDatalistId); ?>"
                data-ticket-country-input
                placeholder="Type or pick from suggestions"
                autocomplete="country-name"
            >
            <datalist id="<?php echo e($countryDatalistId); ?>">
                <?php foreach ($ticketCountryDropdownOptions as $countryOption): ?>
                    <?php if (trim((string) $countryOption) === '') {
                        continue;
                    } ?>
                    <option value="<?php echo e((string) $countryOption); ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <div class="field-help">Pick from suggestions or type the country name if it is not listed.</div>
        </div>
