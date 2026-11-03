<?php

namespace App\Builder;

use App\DTOs\StatsFilterDto;
use App\Enums\StatsRangeType;
use App\Helpers\AppHelper;
use App\Models\Campaign;
use App\Models\StatsCustomPropertyValue;
use App\Services\StatsCustomPropertyService;

/**
 * Builds the shared array of SQL condition fragments (date windows, team/project
 * scoping, tracking/location/device filters, custom-property and custom-event
 * narrowing, and the user/visitor RIGHT JOINs) from a StatsFilterDto.
 *
 * Consumed by the bucket aggregation (getAggregatedData / getAggregatedGroupedData
 * / getAggregatedPropertyData) and the MRR aggregation, so a single filter set
 * narrows both period stats and MRR identically (channel/property attribution).
 */
class StatsFilterConditionsBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(StatsFilterDto $filters): array
    {
        $conditions = StatsRangeType::conditions($filters->rangeStart, $filters->rangeAmount)[$filters->rangeType->value];

        $teamCondition = ' stats.team_id = '.AppHelper::getTeamId().' ';

        $projectCondition = '';
        if (! empty($filters->projectId)) {
            $projectCondition .= " AND stats.project_id = {$filters->projectId} ";
        }

        // Members limited to selected projects: "all" only spans their projects,
        // and a project outside the selection yields nothing instead of its data.
        $accessibleProjectIds = AppHelper::accessibleProjectIds();
        if ($accessibleProjectIds !== null) {
            if (empty($filters->projectId) && filled($accessibleProjectIds)) {
                $projectCondition .= ' AND stats.project_id IN ('.implode(',', $accessibleProjectIds).') ';
            } elseif (! in_array($filters->projectId, $accessibleProjectIds, true)) {
                $projectCondition .= ' AND FALSE ';
            }
        }

        $conditions['teamCondition'] = $teamCondition;
        $conditions['projectCondition'] = $teamCondition.$projectCondition;

        $trackingCondition = '';

        if (! empty($filters->locationCountry)) {
            $locationCountryCondition = ($filters->locationCountry === StatsFilterDto::TRACK_ORGANIC) ? ' IS NULL ' : " = {$filters->locationCountry} ";
            $trackingCondition .= " AND stats.stats_location_country_id $locationCountryCondition ";
        }

        if (! empty($filters->locationRegion)) {
            $locationRegionCondition = ($filters->locationRegion === StatsFilterDto::TRACK_ORGANIC) ? ' IS NULL ' : " = {$filters->locationRegion} ";
            $trackingCondition .= " AND stats.stats_location_region_id $locationRegionCondition ";
        }

        if (! empty($filters->locationCity)) {
            $locationCityCondition = ($filters->locationCity === StatsFilterDto::TRACK_ORGANIC) ? ' IS NULL ' : " = {$filters->locationCity} ";
            $trackingCondition .= " AND stats.stats_location_city_id $locationCityCondition ";
        }

        if (! empty($filters->deviceType)) {
            $deviceTypeCondition = ($filters->deviceType === StatsFilterDto::TRACK_ORGANIC) ? ' IS NULL ' : " = {$filters->deviceType} ";
            $trackingCondition .= " AND stats.stats_device_type_id $deviceTypeCondition ";
        }

        if (! empty($filters->devicePlatform)) {
            $devicePlatformCondition = ($filters->devicePlatform === StatsFilterDto::TRACK_ORGANIC) ? ' IS NULL ' : " = {$filters->devicePlatform} ";
            $trackingCondition .= " AND stats.stats_device_platform_id $devicePlatformCondition ";
        }

        if (! empty($filters->deviceBrowser)) {
            $deviceBrowserCondition = ($filters->deviceBrowser === StatsFilterDto::TRACK_ORGANIC) ? ' IS NULL ' : " = {$filters->deviceBrowser} ";
            $trackingCondition .= " AND stats.stats_device_browser_id $deviceBrowserCondition ";
        }

        if (! empty($filters->trackReferer)) {
            $trackRefererCondition = ($filters->trackReferer === StatsFilterDto::TRACK_ORGANIC) ? ' IS NULL ' : " = {$filters->trackReferer} ";
            $trackingCondition .= " AND stats.stats_track_referer_id $trackRefererCondition ";
        }

        if (! empty($filters->trackSource)) {
            $trackSourceCondition = ($filters->trackSource === StatsFilterDto::TRACK_ORGANIC) ? ' IS NULL ' : " = {$filters->trackSource} ";
            $trackingCondition .= " AND stats.stats_track_source_id $trackSourceCondition ";
        }

        if (! empty($filters->trackMedium) && $filters->trackSource !== StatsFilterDto::TRACK_ORGANIC) {
            $trackingCondition .= " AND stats.stats_track_medium_id = {$filters->trackMedium} ";
        }
        if (! empty($filters->trackCampaign) && $filters->trackSource !== StatsFilterDto::TRACK_ORGANIC) {
            $trackingCondition .= " AND stats.stats_track_campaign_id = {$filters->trackCampaign} ";
        }
        if (! empty($filters->trackTerm) && $filters->trackSource !== StatsFilterDto::TRACK_ORGANIC) {
            $trackingCondition .= " AND stats.stats_track_term_id = {$filters->trackTerm} ";
        }
        if (! empty($filters->trackContent) && $filters->trackSource !== StatsFilterDto::TRACK_ORGANIC) {
            $trackingCondition .= " AND stats.stats_track_content_id = {$filters->trackContent} ";
        }

        if (! empty($filters->pageEntry)) {
            $pageEntryCondition = ($filters->pageEntry === StatsFilterDto::TRACK_ORGANIC) ? ' IS NULL ' : " = {$filters->pageEntry} ";
            $trackingCondition .= " AND stats.stats_page_entry_id $pageEntryCondition ";
        }

        if (! empty($filters->campaign)) {
            $campaignTrackIds = Campaign::find($filters->campaign)?->statsTrackCampaigns()->pluck('id');

            if ($campaignTrackIds?->isNotEmpty()) {
                $trackingCondition .= ' AND stats.stats_track_campaign_id IN ('.$campaignTrackIds->implode(',').') ';
            } else {
                // A campaign with no linked utm dimensions must match nothing.
                $trackingCondition .= ' AND 1 = 0 ';
            }
        }

        $conditions['trackingCondition'] = '';
        $conditions['trackingConditionForEvents'] = '';
        $conditions['trackingConditionForEventsCe'] = '';

        // Conditions on the user/visitor entity tables (aliased su/sv). They are
        // appended to the RIGHT JOINs of the standard grouped queries and used
        // standalone by the grouped properties query.
        $userEntityConditions = '';
        $visitorEntityConditions = '';

        if (! empty($trackingCondition)) {
            $conditions['trackingCondition'] = $trackingCondition;
            $userEntityConditions .= ' '.str_replace('stats.', 'su.', $trackingCondition);
            $visitorEntityConditions .= ' '.str_replace('stats.', 'sv.', $trackingCondition);

            $visitorCondition = str_replace('stats.', 'sv.', $trackingCondition);
            $conditions['trackingConditionForEvents'] = " AND stats.stats_visitor_id IN (SELECT sv.id FROM stats_visitors sv WHERE sv.project_id = stats.project_id{$visitorCondition}) ";
            $conditions['trackingConditionForEventsCe'] = " AND ce.stats_visitor_id IN (SELECT sv.id FROM stats_visitors sv WHERE sv.project_id = ce.project_id{$visitorCondition}) ";
        }

        // Registration-cohort window: scope every user-linked metric to users who
        // registered in [cohortStart, cohortEnd). A registration cohort has no
        // visitor leg, so every stats_visitors surface is forced empty.
        $conditions['cohortConditionUser'] = '';
        $conditions['cohortConditionVisitor'] = '';

        if ($filters->cohortStart !== null && $filters->cohortEnd !== null) {
            $cohortStart = $filters->cohortStart->format('Y-m-d H:i:s');
            $cohortEnd = $filters->cohortEnd->format('Y-m-d H:i:s');

            $conditions['cohortConditionUser'] = " AND stats.time >= '{$cohortStart}' AND stats.time < '{$cohortEnd}' ";
            $conditions['cohortConditionVisitor'] = ' AND 1 = 0 ';

            $userEntityConditions .= " AND su.time >= '{$cohortStart}' AND su.time < '{$cohortEnd}' ";
            $visitorEntityConditions .= ' AND 1 = 0 ';
        }

        $conditions['eventConditionEventName'] = '';
        $conditions['eventConditionEventNameCe'] = '';
        $conditions['eventConditionGroup'] = '';
        $conditions['eventConditionUser'] = '';
        $conditions['eventConditionVisitor'] = '';
        $conditions['propertyConditionUser'] = '';
        $conditions['propertyConditionVisitor'] = '';
        $conditions['propertyConditionForEvents'] = '';
        $conditions['propertyConditionForEventsCe'] = '';
        $conditions['propertyConditionForVisitors'] = '';

        // Custom property filter: narrow users/visitors to the entities bound to
        // the selected property value. Lookups are project-scoped, so the chosen
        // value id is expanded to every equivalent (same property name + value
        // string) id of the team, which makes the filter work on the "All
        // projects" view too.
        if (! empty($filters->propertyValue)) {
            $referenceValue = StatsCustomPropertyValue::with('name')->find($filters->propertyValue);

            if ($referenceValue !== null) {
                $propertyValueIds = StatsCustomPropertyService::equivalentValueIds($referenceValue)->implode(',');

                $userPropertyCondition = fn (string $id, string $table): string => "{$id} IN (SELECT sp.propertyable_id FROM stats_custom_properties sp WHERE sp.propertyable_type = 'stats_user' AND sp.stats_custom_property_value_id IN ({$propertyValueIds}) AND sp.project_id = {$table}.project_id)";
                $visitorPropertyCondition = fn (string $id, string $table): string => "CAST({$id} AS varchar) IN (SELECT sp.propertyable_id FROM stats_custom_properties sp WHERE sp.propertyable_type = 'stats_visitor' AND sp.stats_custom_property_value_id IN ({$propertyValueIds}) AND sp.project_id = {$table}.project_id)";

                $conditions['propertyConditionUser'] = ' AND '.$userPropertyCondition('stats.id', 'stats').' ';
                $conditions['propertyConditionVisitor'] = ' AND '.$visitorPropertyCondition('stats.id', 'stats').' ';

                $userEntityConditions .= ' AND '.$userPropertyCondition('su.id', 'su').' ';
                $visitorEntityConditions .= ' AND '.$visitorPropertyCondition('sv.id', 'sv').' ';

                // events grouped query: only events triggered by an entity bound to the value
                $conditions['propertyConditionForEvents'] = ' AND ('.$userPropertyCondition('stats.stats_user_id', 'stats').' OR '.$visitorPropertyCondition('stats.stats_visitor_id', 'stats').') ';
                $conditions['propertyConditionForEventsCe'] = str_replace('stats.', 'ce.', $conditions['propertyConditionForEvents']);
                $conditions['propertyConditionForVisitors'] = ' AND '.$visitorPropertyCondition('stats.id', 'stats').' ';
            }
        }

        if (! empty($filters->customEventName)) {
            $eventId = $filters->customEventName;

            $conditions['eventConditionEventName'] = " AND stats.stats_custom_event_name_id = {$eventId} ";
            $conditions['eventConditionEventNameCe'] = " AND ce.stats_custom_event_name_id = {$eventId} ";
            // events grouped query: narrow the buckets to the selected event itself
            $conditions['eventConditionGroup'] = " AND stats.id = {$eventId} ";

            $userEventCondition = fn (string $id, string $table): string => "{$id} IN (SELECT DISTINCT ce_f.stats_user_id FROM stats_custom_events ce_f WHERE ce_f.stats_custom_event_name_id = {$eventId} AND ce_f.project_id = {$table}.project_id AND ce_f.stats_user_id IS NOT NULL)";
            $visitorEventCondition = fn (string $id, string $table): string => "{$id} IN (SELECT DISTINCT ce_f.stats_visitor_id FROM stats_custom_events ce_f WHERE ce_f.stats_custom_event_name_id = {$eventId} AND ce_f.project_id = {$table}.project_id AND ce_f.stats_visitor_id IS NOT NULL)";

            $conditions['eventConditionUser'] = ' AND '.$userEventCondition('stats.id', 'stats').' ';
            $conditions['eventConditionVisitor'] = ' AND '.$visitorEventCondition('stats.id', 'stats').' ';

            $userEntityConditions .= ' AND '.$userEventCondition('su.id', 'su').' ';
            $visitorEntityConditions .= ' AND '.$visitorEventCondition('sv.id', 'sv').' ';
        }

        $conditions['userEntityConditions'] = $userEntityConditions;
        $conditions['visitorEntityConditions'] = $visitorEntityConditions;
        $conditions['trackingUserJoinConditions'] = 'RIGHT JOIN stats_users su ON su.id = stats.stats_user_id AND su.project_id = stats.project_id'.$userEntityConditions;
        $conditions['trackingVisitorJoinConditions'] = 'RIGHT JOIN stats_visitors sv ON sv.id = stats.stats_visitor_id AND sv.project_id = stats.project_id'.$visitorEntityConditions;

        return $conditions;
    }
}
