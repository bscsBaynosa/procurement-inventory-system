<?php
// Legacy placeholder to avoid PSR-4 autoload conflicts.
// Use App\Services\PDFService in src/Services/PDFService.php.
namespace App\Services;

use Mpdf\Mpdf;

class PDFService
{
	/**
	 * Create a preconfigured mPDF instance for A4 bond paper in portrait with reasonable margins.
	 */
	private function createMpdf(): Mpdf
	{
		return new Mpdf([
			'format' => 'A4',        // A4 bond paper size
			'orientation' => 'P',    // Portrait
			'margin_left' => 12,
			'margin_right' => 12,
			'margin_top' => 12,
			'margin_bottom' => 12,
		]);
	}

	public function generatePurchaseRequestPDF(array $requestData): void
	{
		$mpdf = $this->createMpdf();
		$html = '<h1 style="text-align:center">Purchase Request</h1>';
		$html .= '<table width="100%" border="1" cellspacing="0" cellpadding="6">';
		foreach ($requestData as $key => $value) {
			$k = htmlspecialchars((string)$key);
			$v = htmlspecialchars((string)$value);
			$html .= "<tr><td><strong>{$k}</strong></td><td>{$v}</td></tr>";
		}
		$html .= '</table>';
		$mpdf->WriteHTML($html);
		$mpdf->Output('purchase_request.pdf', 'D');
	}

	public function generateInventoryReportPDF(array $meta, array $inventoryRows, string $output = 'D', ?string $fileName = null): void
	{
		$mpdf = $this->createMpdf();
		// Footer with Prepared By and page numbers if available
		$prepared = isset($meta['Prepared By']) ? (string)$meta['Prepared By'] : '';
		$mpdf->SetFooter(($prepared !== '' ? ('Prepared by: ' . $prepared . ' | ') : '') . 'Page {PAGENO}/{nbpg}');
		$header = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">'
			. '<div style="font-size:22px;font-weight:700;">Inventory Report</div>'
			. '<div style="font-size:12px;color:#64748b;">Generated: ' . htmlspecialchars(date('Y-m-d H:i')) . '</div>'
			. '</div>';
		$metaHtml = '<table width="100%" border="1" cellspacing="0" cellpadding="6" style="margin-bottom:10px;">';
		foreach ($meta as $k => $v) {
			if ($k === '_summary') { continue; }
			$metaHtml .= '<tr><td style="width:30%;font-weight:600;">' . htmlspecialchars((string)$k) . '</td><td>' . htmlspecialchars((string)$v) . '</td></tr>';
		}
		$metaHtml .= '</table>';
		// Optional monthly/period summary similar to medical supplies reference
		$summaryHtml = '';
		if (isset($meta['_summary']) && is_array($meta['_summary']) && count($meta['_summary']) > 0) {
			$sumRows = '';
			$totBeg = $totDel = $totCon = $totEnd = 0;
			foreach ($meta['_summary'] as $s) {
				$name = htmlspecialchars((string)($s['name'] ?? ''), ENT_QUOTES, 'UTF-8');
				$unit = htmlspecialchars((string)($s['unit'] ?? ''), ENT_QUOTES, 'UTF-8');
				$beg = (int)($s['beginning'] ?? 0);
				$del = (int)($s['delivered'] ?? 0);
				$con = (int)($s['consumed'] ?? 0);
				$end = (int)($s['ending'] ?? 0);
				$sumRows .= '<tr>'
					. '<td>' . $name . '</td>'
					. '<td>' . $unit . '</td>'
					. '<td style="text-align:right;">' . $beg . '</td>'
					. '<td style="text-align:right;">' . $del . '</td>'
					. '<td style="text-align:right;">' . $con . '</td>'
					. '<td style="text-align:right;">' . $end . '</td>'
					. '</tr>';
				$totBeg += $beg; $totDel += $del; $totCon += $con; $totEnd += $end;
			}
			$sumRows .= '<tr>'
				. '<td colspan="2" style="font-weight:700;">TOTAL</td>'
				. '<td style="text-align:right;font-weight:700;">' . $totBeg . '</td>'
				. '<td style="text-align:right;font-weight:700;">' . $totDel . '</td>'
				. '<td style="text-align:right;font-weight:700;">' . $totCon . '</td>'
				. '<td style="text-align:right;font-weight:700;">' . $totEnd . '</td>'
				. '</tr>';
			$summaryHtml = '<div style="font-size:16px;font-weight:700;margin:10px 0 6px;">Summary</div>'
				. '<table width="100%" border="1" cellspacing="0" cellpadding="6" style="margin-bottom:10px;">'
				. '<thead><tr><th>Item</th><th>Unit</th><th>Beginning</th><th>New Delivered</th><th>Total Consumed</th><th>Ending Count</th></tr></thead>'
				. '<tbody>' . $sumRows . '</tbody></table>';
		}
		$rows = '';
		foreach ($inventoryRows as $r) {
			$rows .= '<tr>'
				. '<td>' . htmlspecialchars((string)($r['name'] ?? '')) . '</td>'
				. '<td>' . htmlspecialchars((string)($r['unit'] ?? '')) . '</td>'
				. '<td style="text-align:right;">' . (int)($r['quantity'] ?? 0) . '</td>'
				. '<td style="text-align:right;">' . (int)($r['minimum_quantity'] ?? 0) . '</td>'
				. '<td>' . (isset($r['is_low']) && $r['is_low'] ? 'LOW' : 'OK') . '</td>'
				. '</tr>';
		}
		$table = '<div style="font-size:16px;font-weight:700;margin:10px 0 6px;">Snapshot (Current Stocks)</div>'
			. '<table width="100%" border="1" cellspacing="0" cellpadding="6">'
			. '<thead><tr><th>Item</th><th>Unit</th><th>Stocks</th><th>Min</th><th>Status</th></tr></thead><tbody>'
			. $rows . '</tbody></table>';
		$html = $header . $metaHtml . $summaryHtml . $table;
		$mpdf->WriteHTML($html);
		$fname = $fileName ?: 'inventory_report.pdf';
		$mpdf->Output($fname, $output);
	}

	public function generatePurchaseOrderPDF(array $poData): void
	{
		$mpdf = $this->createMpdf();
		$header = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">'
			. '<div style="font-size:22px;font-weight:700;">Purchase Order</div>'
			. '<div style="font-size:12px;color:#64748b;">Generated: ' . htmlspecialchars(date('Y-m-d H:i')) . '</div>'
			. '</div>';
		$rows = '';
		foreach ($poData as $k => $v) {
			$rows .= '<tr><td style="width:30%;font-weight:600;">' . htmlspecialchars((string)$k) . '</td><td>' . htmlspecialchars((string)$v) . '</td></tr>';
		}
		$html = $header . '<table width="100%" border="1" cellspacing="0" cellpadding="6">' . $rows . '</table>';
		$mpdf->WriteHTML($html);
		$filename = (string)($poData['PO Number'] ?? 'purchase_order') . '.pdf';
		$mpdf->Output($filename, 'D');
	}

	public function generateConsumptionReportPDF(array $meta, array $rows, string $output = 'D', ?string $fileName = null): void
	{
		$mpdf = $this->createMpdf();
		$prepared = isset($meta['Prepared By']) ? (string)$meta['Prepared By'] : '';
		$mpdf->SetFooter(($prepared !== '' ? ('Prepared by: ' . $prepared . ' | ') : '') . 'Page {PAGENO}/{nbpg}');
		$header = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">'
			. '<div style="font-size:22px;font-weight:700;">Consumption Report</div>'
			. '<div style="font-size:12px;color:#64748b;">Generated: ' . htmlspecialchars(date('Y-m-d H:i')) . '</div>'
			. '</div>';
		$metaHtml = '<table width="100%" border="1" cellspacing="0" cellpadding="6" style="margin-bottom:10px;">';
		foreach ($meta as $k => $v) {
			if ($k === '_summary') { continue; }
			$metaHtml .= '<tr><td style="width:30%;font-weight:600;">' . htmlspecialchars((string)$k) . '</td><td>' . htmlspecialchars((string)$v) . '</td></tr>';
		}
		$metaHtml .= '</table>';
		// Optional summary (Beginning / Delivered / Consumed / Ending)
		$summaryHtml = '';
		if (isset($meta['_summary']) && is_array($meta['_summary']) && count($meta['_summary']) > 0) {
			$sumRows = '';
			$totBeg = $totDel = $totCon = $totEnd = 0;
			foreach ($meta['_summary'] as $s) {
				$name = htmlspecialchars((string)($s['name'] ?? ''), ENT_QUOTES, 'UTF-8');
				$beg = (int)($s['beginning'] ?? 0);
				$del = (int)($s['delivered'] ?? 0);
				$con = (int)($s['consumed'] ?? 0);
				$end = (int)($s['ending'] ?? 0);
				$sumRows .= '<tr>'
					. '<td>' . $name . '</td>'
					. '<td style="text-align:right;">' . $beg . '</td>'
					. '<td style="text-align:right;">' . $del . '</td>'
					. '<td style="text-align:right;">' . $con . '</td>'
					. '<td style="text-align:right;">' . $end . '</td>'
					. '</tr>';
				$totBeg += $beg; $totDel += $del; $totCon += $con; $totEnd += $end;
			}
			$sumRows .= '<tr>'
				. '<td style="font-weight:700;">TOTAL</td>'
				. '<td style="text-align:right;font-weight:700;">' . $totBeg . '</td>'
				. '<td style="text-align:right;font-weight:700;">' . $totDel . '</td>'
				. '<td style="text-align:right;font-weight:700;">' . $totCon . '</td>'
				. '<td style="text-align:right;font-weight:700;">' . $totEnd . '</td>'
				. '</tr>';
			$summaryHtml = '<div style="font-size:16px;font-weight:700;margin:10px 0 6px;">Summary</div>'
				. '<table width="100%" border="1" cellspacing="0" cellpadding="6" style="margin-bottom:10px;">'
				. '<thead><tr><th>Item</th><th>Beginning</th><th>New Delivered</th><th>Total Consumed</th><th>Ending Count</th></tr></thead>'
				. '<tbody>' . $sumRows . '</tbody></table>';
		}
		$bodyRows = '';
		foreach ($rows as $r) {
			$bodyRows .= '<tr>'
				. '<td>' . htmlspecialchars((string)($r['name'] ?? '')) . '</td>'
				. '<td>' . (int)($r['previous'] ?? 0) . '</td>'
				. '<td>' . (int)($r['current'] ?? 0) . '</td>'
				. '<td>' . (int)($r['delta'] ?? 0) . '</td>'
				. '<td>' . htmlspecialchars((string)($r['user'] ?? '' )) . '</td>'
				. '<td>' . htmlspecialchars((string)($r['at'] ?? '' )) . '</td>'
				. '</tr>';
		}
		$table = '<table width="100%" border="1" cellspacing="0" cellpadding="6">'
			. '<thead><tr><th>Item</th><th>Last Count</th><th>Current Count</th><th>Consumed(+)/Delivered(-)</th><th>Updated By</th><th>Updated At</th></tr></thead><tbody>'
			. $bodyRows . '</tbody></table>';
		$html = $header . $metaHtml . $summaryHtml . $table;
		$mpdf->WriteHTML($html);
		$fname = $fileName ?: 'consumption_report.pdf';
		$mpdf->Output($fname, $output);
	}


	/**
	 * Generate a Canvassing form PDF (landscape) with supplier columns and items grid, and save to a file.
	 * @param string $prNumber PR identifier (e.g., 2025001)
	 * @param array $items List of item strings like "Name × Qty Unit"
	 * @param array $supplierNames List of supplier names (3–5 recommended)
	 * @param string $filePath Destination absolute path to save the PDF
	 */
	public function generateCanvassingPDFToFile(string $prNumber, array $items, array $supplierNames, string $filePath): void
	{
		$mpdf = new Mpdf(['format' => 'A4', 'orientation' => 'L', 'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 10, 'margin_bottom' => 10]);
		$colCount = max(3, min(5, count($supplierNames)));
		$names = array_slice(array_values($supplierNames), 0, $colCount);
		// Header
		$html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">'
			. '<div style="font-size:20px;font-weight:700;">Canvassing Form</div>'
			. '<div style="font-size:12px;color:#64748b;">PR: ' . htmlspecialchars($prNumber) . '</div>'
			. '</div>';
		// Build table header
		$html .= '<table width="100%" border="1" cellspacing="0" cellpadding="6">';
		$html .= '<thead><tr>'
			. '<th style="width:35%;text-align:left;">Specification</th>';
		foreach ($names as $n) {
			$html .= '<th style="text-align:left;">' . htmlspecialchars((string)$n) . '</th>';
		}
		// Pad to consistent 5 columns visually
		for ($i = count($names); $i < 5; $i++) { $html .= '<th style="text-align:left;">&nbsp;</th>'; }
		$html .= '</tr></thead><tbody>';
		// Item rows
		foreach ($items as $it) {
			$html .= '<tr>'
				. '<td>' . htmlspecialchars((string)$it) . '</td>';
			for ($i = 0; $i < max($colCount, 5); $i++) { $html .= '<td>&nbsp;</td>'; }
			$html .= '</tr>';
		}
		// Awarded section
		$html .= '<tr>'
			. '<td style="font-weight:700;">AWARDED TO:</td>'
			. '<td colspan="' . max($colCount, 5) . '">&nbsp;</td>'
			. '</tr>';
		$html .= '</tbody></table>';
		// Signatures
		$html .= '<div style="display:flex;justify-content:space-between;margin-top:12px;">'
			. '<div style="width:32%;text-align:center;"><div style="height:48px;"></div><div style="border-top:1px solid #999;padding-top:6px;">Prepared by</div></div>'
			. '<div style="width:32%;text-align:center;"><div style="height:48px;"></div><div style="border-top:1px solid #999;padding-top:6px;">Checked by</div></div>'
			. '<div style="width:32%;text-align:center;"><div style="height:48px;"></div><div style="border-top:1px solid #999;padding-top:6px;">Approved by</div></div>'
			. '</div>';
		$mpdf->WriteHTML($html);
		$mpdf->Output($filePath, 'F');
	}
}
