import { beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended, registerEndpoint } from '@nuxt/test-utils/runtime'
import ReportTable from '~/components/report/ReportTable.vue'
import { seedState } from '../support/api'
import { meta } from '../support/fixtures'
import * as rows from '../support/rows'
import type { ReportColumn } from '~/utils/reportColumns'
import {
  CAMPAIGN_COLUMNS,
  CONTENT_COLUMNS,
  CONVERSION_COLUMNS,
  COUNTRY_COLUMNS,
  EVENT_COLUMNS,
  EVENT_PROP_COLUMNS,
  GOAL_COLUMNS,
  LANDING_COLUMNS,
  PAGE_COLUMNS,
  SOURCE_COLUMNS,
  TECH_COLUMNS,
  TOP_PAGES_COLUMNS,
  TOP_SOURCES_COLUMNS
} from '~/utils/reportDefinitions'

const EMPTY = '—'

interface Case {
  title: string
  report: string
  columns: ReportColumn[]
  row: Record<string, unknown>
  params?: Record<string, string>
}

const cases: Case[] = [
  { title: 'pages (top)', report: 'pages', columns: PAGE_COLUMNS.top, row: rows.pagesRow, params: { kind: 'top' } },
  { title: 'pages (entry)', report: 'pages', columns: PAGE_COLUMNS.entry, row: rows.pagesRow, params: { kind: 'entry' } },
  { title: 'pages (exit)', report: 'pages', columns: PAGE_COLUMNS.exit, row: rows.pagesRow, params: { kind: 'exit' } },
  { title: 'overview top pages', report: 'pages', columns: TOP_PAGES_COLUMNS, row: rows.pagesRow },
  { title: 'landing pages', report: 'landing-pages', columns: LANDING_COLUMNS, row: rows.landingPageRow },
  { title: 'sources (channel)', report: 'sources', columns: SOURCE_COLUMNS.channel, row: rows.sourcesRow, params: { group: 'channel' } },
  { title: 'sources (source)', report: 'sources', columns: SOURCE_COLUMNS.source, row: rows.sourcesRow, params: { group: 'source' } },
  { title: 'sources (referrer)', report: 'sources', columns: SOURCE_COLUMNS.referrer, row: rows.sourcesRow, params: { group: 'referrer' } },
  { title: 'overview top sources', report: 'sources', columns: TOP_SOURCES_COLUMNS, row: rows.sourcesRow },
  { title: 'campaigns', report: 'campaigns', columns: CAMPAIGN_COLUMNS, row: rows.campaignsRow },
  { title: 'tech (device)', report: 'tech', columns: TECH_COLUMNS.device, row: rows.techRow, params: { group: 'device' } },
  { title: 'tech (browser)', report: 'tech', columns: TECH_COLUMNS.browser, row: rows.techRow, params: { group: 'browser' } },
  { title: 'tech (os)', report: 'tech', columns: TECH_COLUMNS.os, row: rows.techRow, params: { group: 'os' } },
  { title: 'countries', report: 'countries', columns: COUNTRY_COLUMNS, row: rows.countriesRow },
  { title: 'events', report: 'events', columns: EVENT_COLUMNS, row: rows.eventsRow },
  { title: 'event properties', report: 'events/signup/props', columns: EVENT_PROP_COLUMNS, row: rows.eventPropsRow },
  { title: 'content', report: 'content', columns: CONTENT_COLUMNS, row: rows.contentRow },
  { title: 'goals', report: 'goals', columns: GOAL_COLUMNS, row: rows.goalsRow },
  { title: 'conversions', report: 'conversions', columns: CONVERSION_COLUMNS, row: rows.conversionsRow }
]

let currentRow: Record<string, unknown> = {}

for (const report of new Set(cases.map(c => c.report))) {
  registerEndpoint(`/api/v1/sites/1/reports/${report}`, {
    method: 'GET',
    handler: () => ({ data: { rows: [currentRow], total_rows: 1 }, meta })
  })
}

async function mountReport(testCase: Case) {
  currentRow = testCase.row
  const wrapper = await mountSuspended(ReportTable, {
    props: { report: testCase.report, columns: testCase.columns, params: testCase.params ?? {}, paginate: false, csv: false }
  })
  await new Promise(resolve => setTimeout(resolve, 30))
  await nextTick()
  return wrapper
}

describe('report tables render the documented row schemas', () => {
  beforeEach(() => {
    clearNuxtState()
    clearNuxtData()
    seedState()
  })

  it.each(cases)('$title', async (testCase) => {
    const wrapper = await mountReport(testCase)
    const cells = wrapper.findAll('tbody td')

    expect(cells).toHaveLength(testCase.columns.length)
    testCase.columns.forEach((column, index) => {
      const text = cells[index]!.text().trim()
      expect(text, `${testCase.report}.${column.key} rendered as "${text}"`).not.toBe(EMPTY)
      expect(text, `${testCase.report}.${column.key} rendered empty`).not.toBe('')
    })
  })

  it('formats the documented values of a pages row', async () => {
    const wrapper = await mountReport(cases[0]!)
    const text = wrapper.findAll('tbody td').map(c => c.text().trim())

    expect(text).toEqual(['/pricing', '250', '420', '300', '45s'])
  })

  it('formats channels, money and rates', async () => {
    const content = await mountReport(cases.find(c => c.report === 'content')!)
    expect(content.findAll('tbody td').at(-1)!.text()).toBe('organic_search 300 · direct 200 · social 100')

    const goals = await mountReport(cases.find(c => c.report === 'goals')!)
    expect(goals.findAll('tbody td').map(c => c.text().trim())).toEqual(['Signup', 'event', '45', '9%', '€120.00'])
  })

  it('labels an unknown country instead of leaving the cell empty', async () => {
    const wrapper = await mountReport({ ...cases.find(c => c.report === 'countries')!, row: { ...rows.countriesRow, country: null } })
    expect(wrapper.findAll('tbody td')[0]!.text()).toBe('Unknown')
  })
})
