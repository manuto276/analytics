import type { components, paths } from './api'

export type { components, paths } from './api'

export type Schemas = components['schemas']

type HttpMethod = 'get' | 'post' | 'put' | 'patch' | 'delete'

/** JSON body of the first successful (200/201/202) response of an operation. */
export type ApiResponse<P extends keyof paths, M extends HttpMethod = 'get'>
  = paths[P][M] extends { responses: infer R }
    ? R extends { 200: { content: { 'application/json': infer B } } }
      ? B
      : R extends { 201: { content: { 'application/json': infer B } } }
        ? B
        : R extends { 202: { content: { 'application/json': infer B } } }
          ? B
          : never
    : never

/** JSON request body of an operation. */
export type ApiBody<P extends keyof paths, M extends HttpMethod>
  = paths[P][M] extends { requestBody: { content: { 'application/json': infer B } } } ? B : never

export type Problem = Schemas['Problem']
export type User = Schemas['User']
export type Site = Schemas['Site']
export type SiteInput = Schemas['SiteInput']
export type SiteRole = Schemas['SiteRole']
export type GlobalRole = Schemas['GlobalRole']
export type Member = Schemas['Member']
export type Invitation = Schemas['Invitation']
export type SessionInfo = Schemas['SessionInfo']
export type ConsentConfig = Schemas['ConsentConfig']
export type ConsentConfigInput = Schemas['ConsentConfigInput']
export type ConsentTexts = Schemas['ConsentTexts']
export type ConsentTheme = Schemas['ConsentTheme']
export type ApiKey = Schemas['ApiKey']
export type Goal = Schemas['Goal']
export type GoalInput = Schemas['GoalInput']
export type Funnel = Schemas['Funnel']
export type FunnelInput = Schemas['FunnelInput']
export type CampaignCost = Schemas['CampaignCost']
export type CampaignCostInput = Schemas['CampaignCostInput']
export type CostImportResult = Schemas['CostImportResult']
export type ReportMeta = Schemas['ReportMeta']
export type ReportRow = Schemas['ReportRow']
export type PagesRow = Schemas['PagesRow']
export type LandingPageRow = Schemas['LandingPageRow']
export type SourcesRow = Schemas['SourcesRow']
export type CampaignsRow = Schemas['CampaignsRow']
export type TechRow = Schemas['TechRow']
export type CountriesRow = Schemas['CountriesRow']
export type EventsRow = Schemas['EventsRow']
export type EventPropsRow = Schemas['EventPropsRow']
export type ContentRow = Schemas['ContentRow']
export type GoalsRow = Schemas['GoalsRow']
export type ConversionsRow = Schemas['ConversionsRow']
export type OverviewMetrics = Schemas['OverviewMetrics']
export type TimeseriesPoint = Schemas['TimeseriesPoint']
export type Realtime = Schemas['Realtime']
export type FunnelReport = Schemas['FunnelReport']
export type AttributionReport = Schemas['AttributionReport']
export type CohortReport = Schemas['CohortReport']
export type ConsentReport = Schemas['ConsentReport']
export type JobsStatus = Schemas['JobsStatus']
export type AuditEntry = Schemas['AuditEntry']

export type AuthConfig = ApiResponse<'/auth/config'>['data']

export type Period = components['parameters']['Period']
export type Interval = components['parameters']['Interval']
export type Compare = components['parameters']['Compare']

export type FilterOp = 'is' | 'is_not' | 'contains' | 'prefix' | 'glob'
export type FilterDimension
  = 'page' | 'entry_page' | 'exit_page' | 'host' | 'channel' | 'source' | 'referrer'
    | 'utm_source' | 'utm_medium' | 'utm_campaign' | 'utm_content' | 'utm_term'
    | 'device' | 'browser' | 'os' | 'country' | 'event' | 'content' | 'level'

export interface ReportFilter {
  dim: FilterDimension
  op: FilterOp
  value: string
}
