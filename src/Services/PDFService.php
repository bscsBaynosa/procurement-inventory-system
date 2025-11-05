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
		// Try to enable Poppins font if TTFs are available locally; fall back gracefully.
		$root = @realpath(__DIR__ . '/../../');
		$fontDirs = [];
		$poppinsDir = null;
		$tryDirs = [];
		if ($root) {
			$tryDirs[] = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'fonts';
			$tryDirs[] = $root . DIRECTORY_SEPARATOR . 'fonts';
		}
		foreach ($tryDirs as $d) {
			if (@is_dir($d) && @is_file($d . DIRECTORY_SEPARATOR . 'Poppins-Regular.ttf')) { $poppinsDir = $d; break; }
		}
		$fontData = null; $defaultFont = null;
		if ($poppinsDir) {
			try {
				$defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
				$defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
				$fontDirs = $defaultConfig['fontDir'];
				$fontDirs[] = $poppinsDir;
				$fontData = $defaultFontConfig['fontdata'] + [
					'poppins' => [
						'R' => 'Poppins-Regular.ttf',
						'B' => @is_file($poppinsDir . DIRECTORY_SEPARATOR . 'Poppins-Bold.ttf') ? 'Poppins-Bold.ttf' : 'Poppins-Regular.ttf',
						'I' => @is_file($poppinsDir . DIRECTORY_SEPARATOR . 'Poppins-Italic.ttf') ? 'Poppins-Italic.ttf' : 'Poppins-Regular.ttf',
						'BI' => @is_file($poppinsDir . DIRECTORY_SEPARATOR . 'Poppins-BoldItalic.ttf') ? 'Poppins-BoldItalic.ttf' : (@is_file($poppinsDir . DIRECTORY_SEPARATOR . 'Poppins-Bold.ttf') ? 'Poppins-Bold.ttf' : 'Poppins-Regular.ttf'),
					],
				];
				$defaultFont = 'poppins';
			} catch (\Throwable $ignored) { $poppinsDir = null; }
		}
		$opts = [
			'format' => 'A4',
			'orientation' => 'P',
			'margin_left' => 12,
			'margin_right' => 12,
			'margin_top' => 12,
			'margin_bottom' => 12,
		];
		if ($poppinsDir && $fontData) {
			$opts['fontDir'] = $fontDirs;
			$opts['fontdata'] = $fontData;
			$opts['default_font'] = $defaultFont;
		}
		return new Mpdf($opts);
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

	/**
	 * Generate a consolidated Purchase Request (PR) PDF file for a PR number with item lines.
	 * @param array $meta Keys: pr_number, branch_name, requested_by, prepared_at, justification?, needed_by?
	 * @param array $items Each: [description, unit, qty]
	 * @param string $filePath Destination absolute path
	 */
	public function generatePurchaseRequisitionToFile(array $meta, array $items, string $filePath): void
	{
		// Compact, classic layout to keep single-page by default when items are few
		$mpdf = $this->createMpdf();
		// No footer by default to avoid reserving space that could trigger page 2
		$mpdf->SetFooter('');
		// Global CSS: classic compact styling, minimal padding, no rounded corners
		$css = '<style>
			body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 10pt; }
			table { border-collapse: collapse; }
			th, td { font-size:10pt; padding: 4px; }
			thead { display: table-header-group; }
			tr { page-break-inside: avoid; }
			.small { font-size:9pt; color:#333; }
		</style>';
		$mpdf->WriteHTML($css);
		$pr = htmlspecialchars((string)($meta['pr_number'] ?? ''), ENT_QUOTES, 'UTF-8');
		$branch = htmlspecialchars((string)($meta['branch_name'] ?? ''), ENT_QUOTES, 'UTF-8');
		$reqBy = htmlspecialchars((string)($meta['requested_by'] ?? ''), ENT_QUOTES, 'UTF-8');
		$prepAt = htmlspecialchars((string)($meta['prepared_at'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8');
		$justRaw = (string)($meta['justification'] ?? '');
		$just = nl2br(htmlspecialchars($justRaw, ENT_QUOTES, 'UTF-8'));
		$need = htmlspecialchars((string)($meta['needed_by'] ?? ''), ENT_QUOTES, 'UTF-8');

		// Try to include the same logo used for favicon (logo.png in root/public or public/img), fallback to pocc-logo.svg
		$root = @realpath(__DIR__ . '/../../');
		$public = $root ? ($root . DIRECTORY_SEPARATOR . 'public') : null;
		$cand = array();
		if ($root) { $cand[] = $root . DIRECTORY_SEPARATOR . 'logo.png'; }
		if ($public) {
			$cand[] = $public . DIRECTORY_SEPARATOR . 'logo.png';
			$cand[] = $public . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'logo.png';
		}
		$cand[] = ($public ? $public : (__DIR__ . '/../../public')) . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'pocc-logo.svg';
		$logoHtml = '';
		foreach ($cand as $p) {
			if (@is_file($p)) {
				$data = @file_get_contents($p);
				if ($data !== false) {
					$mime = (strtolower(substr($p, -4)) === '.svg') ? 'image/svg+xml' : 'image/png';
					$src = 'data:' . $mime . ';base64,' . base64_encode($data);
					$logoHtml = '<div style="text-align:center;margin-bottom:6px;"><img src="' . $src . '" width="56" height="56" /></div>';
					break;
				}
			}
		}

		$topTitle = '<div style="text-align:center;font-size:14px;font-weight:700;">PHILIPPINE ONCOLOGY CENTER CORPORATION</div>'
			. '<div style="height:1px;background:#000;margin:6px 0 8px 0;"></div>';

		$revRow = '<table width="100%" border="0" cellspacing="0" cellpadding="2" style="font-size:9px;margin-bottom:6px;">'
			. '<tr>'
			. '<td style="width:15%;">Rev. No.</td>'
			. '<td style="width:25%;border-bottom:1px solid #444;">&nbsp;</td>'
			. '<td style="width:10%;"></td>'
			. '<td style="width:15%;">Effective Date:</td>'
			. '<td style="width:25%;border-bottom:1px solid #444;">' . htmlspecialchars((string)($meta['effective_date'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
			. '</tr>'
			. '</table>';

		$titleRow = '<div style="text-align:center;font-size:11px;font-style:italic;">PURCHASE REQUISITION NO. <span style="font-size:14px;font-weight:700;border-bottom:1px solid #444;padding:0 48px;">' . $pr . '</span></div>';

		$reqMeta = '<table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top:6px;font-size:10px;">'
			. '<tr>'
			. '<td style="width:20%;">Requesting Section:</td>'
			. '<td style="width:35%;border-bottom:1px solid #444;">' . $branch . '</td>'
			. '<td style="width:20%;">&nbsp;</td>'
			. '<td style="width:25%;">&nbsp;</td>'
			. '</tr>'
			. '<tr>'
			. '<td>Reason for Purchase/or Use of Article:</td>'
			. '<td colspan="3" style="border-bottom:1px solid #444;">' . ($just !== '' ? $just : '&nbsp;') . '</td>'
			. '</tr>'
			. '<tr>'
			. '<td>Date Needed:</td>'
			. '<td style="border-bottom:1px solid #444;">' . ($need !== '' ? $need : '&nbsp;') . '</td>'
			. '<td style="text-align:right;">Date Received:</td>'
			. '<td style="border-bottom:1px solid #444;">' . htmlspecialchars((string)($meta['date_received'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
			. '</tr>'
			. '</table>';

		// Items table with QUANTITY / SPECIFICATION header
		$rows = '';
		foreach ($items as $i) {
			$desc = htmlspecialchars((string)($i['description'] ?? ''), ENT_QUOTES, 'UTF-8');
			$unit = htmlspecialchars((string)($i['unit'] ?? ''), ENT_QUOTES, 'UTF-8');
			$qty = (int)($i['qty'] ?? 0);
			$stock = isset($i['stock_on_hand']) ? (string)(int)$i['stock_on_hand'] : '0';
			$usage = isset($i['usage_per_month']) ? (string)(int)$i['usage_per_month'] : '&nbsp;';
			$rows .= '<tr>'
				. '<td style="text-align:center;width:8%;">' . $stock . '</td>'
				. '<td style="text-align:center;width:10%;">' . $usage . '</td>'
				. '<td style="text-align:center;width:10%;">' . $qty . '</td>'
				. '<td style="text-align:center;width:10%;">' . $unit . '</td>'
				. '<td style="width:62%;">' . $desc . '</td>'
				. '</tr>';
		}
		// Do not pad rows — keep content compact to stay on one page when possible
		$itemsTable = '<table width="100%" border="1" cellspacing="0" cellpadding="4" style="margin-top:6px;">'
			. '<thead>'
			. '<tr>'
			. '<th colspan="4" style="text-align:center;">QUANTITY</th>'
			. '<th style="text-align:center;">SPECIFICATION</th>'
			. '</tr>'
			. '<tr>'
			. '<th style="text-align:center;">Stock on hand</th>'
			. '<th style="text-align:center;">Usage per Month</th>'
			. '<th style="text-align:center;">Qty. Needed</th>'
			. '<th style="text-align:center;">Unit</th>'
			. '<th style="text-align:center;">DESCRIPTION</th>'
			. '</tr>'
			. '</thead>'
			. '<tbody>' . $rows . '</tbody>'
			. '</table>';

		// Attachments / Additional instruction
		$attachments = '<table width="100%" border="1" cellspacing="0" cellpadding="6" style="margin-top:6px;">'
			. '<tr><td style="width:18%;">Attachments / Additional instruction:</td><td>&nbsp;</td></tr>'
			. '</table>';

		// Signatures (bottom)
		$signatureVariant = (string)($meta['signature_variant'] ?? 'pr');
		$approvedName = '';
		$approvedDate = '';
		if ($signatureVariant === 'purchase_approval') {
			// PR - Canvass variant: use canvassing approval fields and label "Approved for Purchase"
			$approvedName = htmlspecialchars((string)($meta['purchase_approved_by'] ?? ''), ENT_QUOTES, 'UTF-8');
			$approvedDate = htmlspecialchars((string)($meta['purchase_approved_at'] ?? ''), ENT_QUOTES, 'UTF-8');
			$approvedLabel = 'Approved for Purchase:';
		} else {
			$approvedName = htmlspecialchars((string)($meta['approved_by'] ?? ''), ENT_QUOTES, 'UTF-8');
			$approvedDate = htmlspecialchars((string)($meta['approved_at'] ?? ''), ENT_QUOTES, 'UTF-8');
			$approvedLabel = 'Approved By:';
		}
		$uniformRow = function(string $leftLabel, string $leftValue, string $rightDate): string {
			// Strictly enforce identical box height and prevent text wrapping to keep rows uniform
			$fixedHeight = 48; // px
			$padTop = 8;
			$boxLeft = 'height:' . $fixedHeight . 'px;';
			$boxRight = 'height:' . $fixedHeight . 'px;';
			$nowrap = 'white-space:nowrap; overflow:hidden; text-overflow:ellipsis;';
			return '<tr>'
				. '<td style="width:60%;vertical-align:bottom;">'
					. '<div style="' . $boxLeft . '"></div>'
					. '<div style="border-top:1px solid #999; text-align:center; padding-top:' . $padTop . 'px; min-height:18px;' . $nowrap . '">' . $leftValue . '</div>'
					. '<div style="position:relative; margin-top:-' . ($fixedHeight + $padTop) . 'px; font-size:10px;">' . $leftLabel . '</div>'
				. '</td>'
				. '<td style="width:40%;vertical-align:bottom;">'
					. '<div style="' . $boxRight . '"></div>'
					. '<div style="border-top:1px solid #999; text-align:center; padding-top:' . $padTop . 'px; min-height:18px;' . $nowrap . '">' . $rightDate . '</div>'
					. '<div style="position:relative; margin-top:-' . ($fixedHeight + $padTop) . 'px; font-size:10px;">Date:</div>'
				. '</td>'
				. '</tr>';
		};
		$sign = '<table width="100%" border="1" cellspacing="0" cellpadding="6" style="margin-top:6px;">'
			. $uniformRow('Requisition By:', $reqBy, $prepAt)
			. $uniformRow('Noted By:', htmlspecialchars((string)($meta['noted_by'] ?? ''), ENT_QUOTES, 'UTF-8'), htmlspecialchars((string)($meta['date_received'] ?? ''), ENT_QUOTES, 'UTF-8'))
			. $uniformRow($approvedLabel, $approvedName, $approvedDate)
			. '</table>';

		// Optional Canvassing section (3 suppliers + Awarded To), shown BEFORE attachments
	$canvas = '';
	$cv = isset($meta['canvassed_suppliers']) && is_array($meta['canvassed_suppliers']) ? array_values($meta['canvassed_suppliers']) : [];
	$cv = array_slice($cv, 0, 3);
	$awarded = isset($meta['awarded_to']) ? trim((string)$meta['awarded_to']) : '';
	// Absolute display fallback: if Awarded To is blank but suppliers exist, default to Supplier 1 to avoid empty cell
	if ($awarded === '' && !empty($cv)) { $awarded = (string)$cv[0]; }
		if (!empty($cv) || $awarded !== '') {
			$labels = ['SUPPLIER 1','SUPPLIER 2','SUPPLIER 3','AWARDED TO'];
			$cols = '';
			for ($i=0; $i<3; $i++) {
				$name = isset($cv[$i]) ? htmlspecialchars((string)$cv[$i], ENT_QUOTES, 'UTF-8') : '&nbsp;';
				$cols .= '<td style="text-align:center;vertical-align:top;height:40px;">' . $name . '</td>';
			}
			$awCell = '<td style="text-align:center;vertical-align:top;height:40px;">' . ($awarded !== '' ? htmlspecialchars($awarded, ENT_QUOTES, 'UTF-8') : '&nbsp;') . '</td>';
			// Optional totals row to justify award (expects meta[canvass_totals] = [t1,t2,t3])
			$tot = isset($meta['canvass_totals']) && is_array($meta['canvass_totals']) ? array_values($meta['canvass_totals']) : [];
			$totCells = '';
			for ($i=0; $i<3; $i++) {
				$val = isset($tot[$i]) ? (float)$tot[$i] : null;
				$totCells .= '<td style="text-align:center;vertical-align:top;height:24px; font-weight:600;">' . ($val !== null ? ('₱ ' . number_format($val, 2)) : 'N/A') . '</td>';
			}
			// Show the awarded supplier total in the rightmost cell
			$awardIdx = -1;
			for ($i=0; $i<3; $i++) {
				if ($i < count($cv)) {
					$nm = trim((string)$cv[$i]);
					if ($nm !== '' && strcasecmp($nm, (string)$awarded) === 0) { $awardIdx = $i; break; }
				}
			}
			$awardVal = ($awardIdx >= 0 && isset($tot[$awardIdx]) && $tot[$awardIdx] !== null) ? ('₱ ' . number_format((float)$tot[$awardIdx], 2)) : 'N/A';
			$totAward = '<td style="text-align:center;vertical-align:top;height:24px; font-weight:700;">' . $awardVal . '</td>';
			$head = '<div style="text-align:center;font-weight:700;margin:8px 0 4px;">CANVASSING</div>';
			$canvas = $head
				. '<table width="100%" border="1" cellspacing="0" cellpadding="6">'
				. '<thead><tr>'
				. '<th style="width:25%;text-align:center;">' . $labels[0] . '</th>'
				. '<th style="width:25%;text-align:center;">' . $labels[1] . '</th>'
				. '<th style="width:25%;text-align:center;">' . $labels[2] . '</th>'
				. '<th style="width:25%;text-align:center;">' . $labels[3] . '</th>'
				. '</tr></thead>'
				. '<tbody><tr>' . $cols . $awCell . '</tr>'
				. '<tr>' . $totCells . $totAward . '</tr></tbody>'
				. '</table>';
		}

		$distribution = '<div style="margin-top:6px;font-size:9px;display:flex;justify-content:space-between;">'
			. '<div>Distribution: ORIGINAL – Administrator</div>'
			. '<div>Duplicate: Requesting Section</div>'
			. '</div>';

		// Compose body with canvassing between the items list and attachments (if canvassing present)
		$html = $logoHtml . $topTitle . $revRow . $titleRow . $reqMeta . $itemsTable
			. ($canvas !== '' ? $canvas : '')
			. $attachments . $sign . $distribution;
		$mpdf->WriteHTML($html);
		$mpdf->Output($filePath, 'F');
	}

	/**
	 * Generate a Purchase Order PDF file closely matching the provided form.
	 * @param array $po Associative data: po_number, date, vendor_name, vendor_address, vendor_tin, reference, terms, center, items[], notes, deliver_to, look_for, prepared_by, reviewed_by (optional), approved_by
	 *                  Each item: [description, unit, qty, unit_price, total]
	 * @param string $filePath Destination absolute file path
	 */
	public function generatePurchaseOrderPDFToFile(array $po, string $filePath): void
	{
		$mpdf = new Mpdf(['format' => 'A4', 'orientation' => 'P', 'margin_left' => 8, 'margin_right' => 8, 'margin_top' => 8, 'margin_bottom' => 8]);
		$poNum = htmlspecialchars((string)($po['po_number'] ?? ''));
		$date = htmlspecialchars((string)($po['date'] ?? date('Y-m-d')));
		$vendor = htmlspecialchars((string)($po['vendor_name'] ?? ''));
		$addr = nl2br(htmlspecialchars((string)($po['vendor_address'] ?? ''), ENT_QUOTES, 'UTF-8'));
		$tin = htmlspecialchars((string)($po['vendor_tin'] ?? ''));
		$ref = htmlspecialchars((string)($po['reference'] ?? ''));
		$terms = htmlspecialchars((string)($po['terms'] ?? ''));
		$center = htmlspecialchars((string)($po['center'] ?? ''));
		$notes = nl2br(htmlspecialchars((string)($po['notes'] ?? ''), ENT_QUOTES, 'UTF-8'));
		$deliverTo = nl2br(htmlspecialchars((string)($po['deliver_to'] ?? ''), ENT_QUOTES, 'UTF-8'));
		$lookFor = htmlspecialchars((string)($po['look_for'] ?? ''), ENT_QUOTES, 'UTF-8');
		$prepared = htmlspecialchars((string)($po['prepared_by'] ?? ''), ENT_QUOTES, 'UTF-8');
		$reviewed = htmlspecialchars((string)($po['reviewed_by'] ?? ''), ENT_QUOTES, 'UTF-8');
		$approved = htmlspecialchars((string)($po['approved_by'] ?? ''), ENT_QUOTES, 'UTF-8');

		$header = '<div style="text-align:center;font-weight:800;font-size:20px;">PHILIPPINE ONCOLOGY CENTER CORPORATION</div>'
			. '<div style="text-align:center;font-size:10px;margin-bottom:6px;">Address: Basement, Marian Medical Arts Bldg., Dahlia Street, West Fairview, Quezon City</div>';
		// Top grid Vendor vs PO meta
		$top = '<table width="100%" border="1" cellspacing="0" cellpadding="6" style="margin-bottom:6px;">'
			. '<tr>'
			. '<td style="width:55%;vertical-align:top;">'
			. '<div style="font-size:11px;margin-bottom:6px;"><strong>VENDOR:</strong><br>' . $vendor . '</div>'
			. '<div style="font-size:11px;margin-bottom:6px;"><strong>ADDRESS:</strong><br>' . $addr . '</div>'
			. '<div style="font-size:11px;"><strong>VAT/TIN:</strong> ' . $tin . '</div>'
			. '</td>'
			. '<td style="vertical-align:top;">'
			. '<table width="100%" border="0" cellspacing="0" cellpadding="4" style="font-size:11px;">'
			. '<tr><td style="width:55%;">PO NO:</td><td style="text-align:right;">' . $poNum . '</td></tr>'
			. '<tr><td>DATE:</td><td style="text-align:right;">' . $date . '</td></tr>'
			. '<tr><td>CENTER:</td><td style="text-align:right;">' . $center . '</td></tr>'
			. '<tr><td>REFERENCE:</td><td style="text-align:right;">' . $ref . '</td></tr>'
			. '<tr><td>TERMS OF PAYMENT:</td><td style="text-align:right;">' . $terms . '</td></tr>'
			. '</table>'
			. '</td>'
			. '</tr>'
			. '</table>';

		// Items table
		$itemsRows = '';
		$total = 0.0;
		foreach (($po['items'] ?? []) as $it) {
			$desc = htmlspecialchars((string)($it['description'] ?? ''), ENT_QUOTES, 'UTF-8');
			$unit = htmlspecialchars((string)($it['unit'] ?? ''), ENT_QUOTES, 'UTF-8');
			$qty = (int)($it['qty'] ?? 0);
			$price = (float)($it['unit_price'] ?? 0);
			$line = (float)($it['total'] ?? ($qty * $price));
			$total += $line;
			$itemsRows .= '<tr>'
				. '<td>' . $desc . '</td>'
				. '<td style="text-align:center;">' . $unit . '</td>'
				. '<td style="text-align:center;">' . $qty . '</td>'
				. '<td style="text-align:right;">' . number_format($price, 2) . '</td>'
				. '<td style="text-align:right;">' . number_format($line, 2) . '</td>'
				. '</tr>';
		}
		// Optional nothing follows line to visually close
		$itemsTable = '<table width="100%" border="1" cellspacing="0" cellpadding="6">'
			. '<thead><tr><th>ITEM DESCRIPTION</th><th style="width:8%;">U/M</th><th style="width:8%;">QTY</th><th style="width:15%;">UNIT PRICE</th><th style="width:15%;">TOTAL</th></tr></thead>'
			. '<tbody>' . $itemsRows . '</tbody>'
			. '<tfoot><tr><td colspan="4" style="text-align:right;font-weight:700;">TOTAL:</td><td style="text-align:right;font-weight:700;">₱ ' . number_format($total, 2) . '</td></tr></tfoot>'
			. '</table>';

		// Notes, delivery and signatures
		$footer = '<div style="margin-top:8px;font-size:11px;">'
			. '<div style="margin-bottom:6px;"><strong>NOTES & INSTRUCTIONS:</strong><br>' . $notes . '</div>'
			. '<div style="margin-bottom:8px;text-align:center;">'
			. '<div style="font-weight:700;">PLEASE DELIVER TO:</div>'
			. '<div>PHILIPPINE ONCOLOGY CENTER CORPORATION</div>'
			. '<div>' . $deliverTo . '</div>'
			. '<div>LOOK FOR: ' . $lookFor . '</div>'
			. '</div>'
			. '<table width="100%" border="1" cellspacing="0" cellpadding="8" style="font-size:11px;">'
			. '<tr>'
			. '<td style="width:33%;vertical-align:bottom;">'
			. '<div style="height:48px;"></div>'
			. '<div style="border-top:1px solid #999;text-align:center;padding-top:6px;">PREPARED BY:<br>' . $prepared . '<br><small>Procurement & Gen. Services</small></div>'
			. '</td>'
			. '<td style="width:33%;vertical-align:bottom;">'
			. '<div style="height:48px;"></div>'
			. '<div style="border-top:1px solid #999;text-align:center;padding-top:6px;">REVIEWED BY:<br>' . ($reviewed !== '' ? $reviewed : '&nbsp;') . '<br><small>Finance Officer</small></div>'
			. '</td>'
			. '<td style="width:34%;vertical-align:bottom;">'
			. '<div style="height:48px;"></div>'
			. '<div style="border-top:1px solid #999;text-align:center;padding-top:6px;">APPROVED BY:<br>' . $approved . '<br><small>Administrator</small></div>'
			. '</td>'
			. '</tr>'
			. '</table>'
			. '</div>';

		$html = $header . $top . $itemsTable . $footer;
		$mpdf->WriteHTML($html);
		$mpdf->Output($filePath, 'F');
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
	 * Generate an RFP (Request For Payment) PDF file based on the client's reference.
	 * @param array $rfp keys: pr_number?, po_number?, pay_to, center, date_requested, date_needed, nature, particulars[[desc,amount]], total, requested_by
	 * @param string $filePath Destination absolute path
	 */
	public function generateRFPToFile(array $rfp, string $filePath): void
	{
		$mpdf = new Mpdf(['format' => 'A4', 'orientation' => 'P', 'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 10, 'margin_bottom' => 10]);
		$payTo = htmlspecialchars((string)($rfp['pay_to'] ?? ''), ENT_QUOTES, 'UTF-8');
		$center = htmlspecialchars((string)($rfp['center'] ?? ''), ENT_QUOTES, 'UTF-8');
		$dateReq = htmlspecialchars((string)($rfp['date_requested'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8');
		$dateNeed = htmlspecialchars((string)($rfp['date_needed'] ?? ''), ENT_QUOTES, 'UTF-8');
		$nature = (string)($rfp['nature'] ?? 'payment_to_supplier');
		$pr = htmlspecialchars((string)($rfp['pr_number'] ?? ''), ENT_QUOTES, 'UTF-8');
		$po = htmlspecialchars((string)($rfp['po_number'] ?? ''), ENT_QUOTES, 'UTF-8');
		$requestedBy = htmlspecialchars((string)($rfp['requested_by'] ?? ''), ENT_QUOTES, 'UTF-8');
		$parts = (array)($rfp['particulars'] ?? []);
		$total = (float)($rfp['total'] ?? 0);
		$totFmt = number_format($total, 2);
		$amountWords = $this->numberToWordsPhp((int)round($total)) . ' PESOS';

		$check = function(string $key) use ($nature): string { return $nature === $key ? 'X' : '&nbsp;'; };

		$header = '<div style="text-align:center;font-weight:800;font-size:16px;">PHILIPPINE ONCOLOGY CENTER CORPORATION</div>'
			. '<div style="text-align:center;font-size:10px;margin-bottom:4px;">ACCOUNT (' . $center . ')</div>'
			. '<div style="text-align:center;font-weight:800;">REQUEST FOR PAYMENT</div>';
		$meta = '<table width="100%" border="1" cellspacing="0" cellpadding="6" style="margin-top:6px;">'
			. '<tr>'
			. '<td style="width:65%;vertical-align:top;">'
			. '<div><strong>Pay to:</strong> ' . $payTo . '</div>'
			. '<div style="margin-top:6px;"><strong>Nature of Request:</strong> '
			. ' ( ) Cash Advance &nbsp; (' . $check('payment_to_supplier') . ') Payment to Supplier &nbsp; (' . $check('pcf_replenishment') . ') PCF Replenishment'
			. '</div>'
			. '<div style="margin-top:4px;"> ( ) JEHCP Reimbursement &nbsp; ( ) Others, please specify: _____________________________</div>'
			. '<div style="margin-top:6px;font-size:10px;"><em>NOTE: Please attach all documents to support the request.</em></div>'
			. '</td>'
			. '<td style="vertical-align:top;">'
			. '<table width="100%" border="0" cellspacing="0" cellpadding="4" style="font-size:11px;">'
			. '<tr><td style="width:55%;">No:</td><td style="text-align:right;">&nbsp;</td></tr>'
			. '<tr><td>Date Requested:</td><td style="text-align:right;">' . $dateReq . '</td></tr>'
			. '<tr><td>Date Needed:</td><td style="text-align:right;">' . $dateNeed . '</td></tr>'
			. '</table>'
			. '</td>'
			. '</tr>'
			. '</table>';

		$rows = '';
		foreach ($parts as $p) {
			$desc = htmlspecialchars((string)($p['desc'] ?? ''), ENT_QUOTES, 'UTF-8');
			$amt = number_format((float)($p['amount'] ?? 0), 2);
			$rows .= '<tr><td>' . $desc . '</td><td style="text-align:right;">' . $amt . '</td></tr>';
		}
		$table = '<table width="100%" border="1" cellspacing="0" cellpadding="6" style="margin-top:6px;">'
			. '<thead><tr><th>PARTICULARS</th><th style="width:30%;">AMOUNT</th></tr></thead>'
			. '<tbody>' . $rows . '</tbody>'
			. '<tfoot><tr><td style="text-align:right;font-weight:700;">TOTAL</td><td style="text-align:right;font-weight:700;">Php ' . $totFmt . '</td></tr></tfoot>'
			. '</table>';

		$amounts = '<table width="100%" border="1" cellspacing="0" cellpadding="6" style="margin-top:6px;">'
			. '<tr><td><strong>Amount in words:</strong><br>' . htmlspecialchars($amountWords, ENT_QUOTES, 'UTF-8') . '</td>'
			. '<td style="width:40%;"><strong>Amount in figures:</strong><br>Php ' . $totFmt . '</td></tr>'
			. '</table>';

		$sign = '<table width="100%" border="1" cellspacing="0" cellpadding="10" style="margin-top:6px;">'
			. '<tr>'
			. '<td style="width:33%;vertical-align:bottom;"><div style="height:38px;"></div><div style="border-top:1px solid #999;text-align:center;padding-top:6px;">Requested by:<br>' . $requestedBy . '</div></td>'
			. '<td style="width:33%;vertical-align:bottom;"><div style="height:38px;"></div><div style="border-top:1px solid #999;text-align:center;padding-top:6px;">Checked by:</div></td>'
			. '<td style="width:34%;vertical-align:bottom;"><div style="height:38px;"></div><div style="border-top:1px solid #999;text-align:center;padding-top:6px;">Approved by:</div></td>'
			. '</tr>'
			. '</table>';

		$html = $header . $meta . $table . $amounts . $sign;
		$mpdf->WriteHTML($html);
		$mpdf->Output($filePath, 'F');
	}

	// Minimal number-to-words for pesos (integer part only)
	private function numberToWordsPhp(int $num): string
	{
		$ones = ['', 'ONE','TWO','THREE','FOUR','FIVE','SIX','SEVEN','EIGHT','NINE','TEN','ELEVEN','TWELVE','THIRTEEN','FOURTEEN','FIFTEEN','SIXTEEN','SEVENTEEN','EIGHTEEN','NINETEEN'];
		$tens = ['', '', 'TWENTY','THIRTY','FORTY','FIFTY','SIXTY','SEVENTY','EIGHTY','NINETY'];
		$scale = ['',' THOUSAND',' MILLION',' BILLION'];
		if ($num === 0) return 'ZERO';
		$words = '';
		$i = 0;
		while ($num > 0 && $i < count($scale)) {
			$chunk = $num % 1000;
			if ($chunk) {
				$chunkWords = '';
				$h = intdiv($chunk, 100);
				$r = $chunk % 100;
				if ($h) { $chunkWords .= $ones[$h] . ' HUNDRED'; if ($r) $chunkWords .= ' '; }
				if ($r) {
					if ($r < 20) { $chunkWords .= $ones[$r]; }
					else { $chunkWords .= $tens[intdiv($r,10)]; if ($r%10) $chunkWords .= '-' . $ones[$r%10]; }
				}
				$words = trim($chunkWords) . $scale[$i] . ($words ? ' ' . $words : '');
			}
			$num = intdiv($num, 1000);
			$i++;
		}
		return $words;
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
		// Match the PR look & structure (portrait) with the same header and sectioning
		$mpdf = new Mpdf(['format' => 'A4', 'orientation' => 'P', 'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 10, 'margin_bottom' => 10]);

		// Try to embed the same logo as PR
		$root = @realpath(__DIR__ . '/../../');
		$public = $root ? ($root . DIRECTORY_SEPARATOR . 'public') : null;
		$cand = [];
		if ($root) { $cand[] = $root . DIRECTORY_SEPARATOR . 'logo.png'; }
		if ($public) {
			$cand[] = $public . DIRECTORY_SEPARATOR . 'logo.png';
			$cand[] = $public . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'logo.png';
		}
		$cand[] = ($public ? $public : (__DIR__ . '/../../public')) . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'pocc-logo.svg';
		$logoHtml = '';
		foreach ($cand as $p) {
			if (@is_file($p)) {
				$data = @file_get_contents($p);
				if ($data !== false) {
					$mime = (strtolower(substr($p, -4)) === '.svg') ? 'image/svg+xml' : 'image/png';
					$src = 'data:' . $mime . ';base64,' . base64_encode($data);
					$logoHtml = '<div style="text-align:center;margin-bottom:6px;"><img src="' . $src . '" width="56" height="56" /></div>';
					break;
				}
			}
		}

		$topTitle = '<div style="text-align:center;font-size:14px;font-weight:700;">PHILIPPINE ONCOLOGY CENTER CORPORATION</div>'
			. '<div style="height:1px;background:#000;margin:6px 0 8px 0;"></div>';
		$revRow = '<table width="100%" border="0" cellspacing="0" cellpadding="2" style="font-size:9px;margin-bottom:6px;">'
			. '<tr>'
			. '<td style="width:15%;">Rev. No.</td>'
			. '<td style="width:25%;border-bottom:1px solid #444;">&nbsp;</td>'
			. '<td style="width:10%;"></td>'
			. '<td style="width:15%;">Effective Date:</td>'
			. '<td style="width:25%;border-bottom:1px solid #444;">' . htmlspecialchars(date('Y-m-d')) . '</td>'
			. '</tr>'
			. '</table>';
		$titleRow = '<div style="text-align:center;font-size:11px;font-weight:700;">CANVASSING</div>'
			. '<div style="text-align:center;font-size:11px;font-style:italic;margin-top:2px;">PURCHASE REQUISITION NO. <span style="font-size:14px;font-weight:700;border-bottom:1px solid #444;padding:0 48px;">' . htmlspecialchars($prNumber) . '</span></div>';

		// Items table (QUANTITY / SPECIFICATION) — same as PR
		$rows = '';
		foreach ($items as $it) {
			// Items come as simple strings "Name × Qty Unit"; split best-effort
			$label = (string)$it;
			$desc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
			$rows .= '<tr>'
				. '<td style="text-align:center;width:8%;">&nbsp;</td>'
				. '<td style="text-align:center;width:10%;">&nbsp;</td>'
				. '<td style="text-align:center;width:10%;">&nbsp;</td>'
				. '<td style="text-align:center;width:10%;">&nbsp;</td>'
				. '<td style="width:62%;">' . $desc . '</td>'
				. '</tr>';
		}
		$itemsTable = '<table width="100%" border="1" cellspacing="0" cellpadding="4" style="margin-top:6px;">'
			. '<thead>'
			. '<tr>'
			. '<th colspan="4" style="text-align:center;">QUANTITY</th>'
			. '<th style="text-align:center;">SPECIFICATION</th>'
			. '</tr>'
			. '<tr>'
			. '<th style="text-align:center;">Stock on hand</th>'
			. '<th style="text-align:center;">Usage per Month</th>'
			. '<th style="text-align:center;">Qty. Needed</th>'
			. '<th style="text-align:center;">Unit</th>'
			. '<th style="text-align:center;">DESCRIPTION</th>'
			. '</tr>'
			. '</thead>'
			. '<tbody>' . $rows . '</tbody>'
			. '</table>';

		// CANVASSING block with Supplier 1..3 and Awarded To — large space for quotes
		$labels = ['SUPPLIER 1', 'SUPPLIER 2', 'SUPPLIER 3'];
		$colHeads = '';
		for ($i = 0; $i < 3; $i++) { $colHeads .= '<th style="text-align:center;">' . $labels[$i] . '</th>'; }
		$cells = '';
		for ($i = 0; $i < 3; $i++) {
			$nm = isset($supplierNames[$i]) ? htmlspecialchars((string)$supplierNames[$i], ENT_QUOTES, 'UTF-8') : '&nbsp;';
			$cells .= '<td style="vertical-align:top;"><div style="font-weight:700;margin-bottom:4px;text-align:center;">' . $nm . '</div><div style="height:120px;">&nbsp;</div></td>';
		}
		$canvassing = '<div style="text-align:center;font-weight:700;margin:8px 0 4px;">CANVASSING</div>'
			. '<table width="100%" border="1" cellspacing="0" cellpadding="6">'
			. '<thead><tr>' . $colHeads . '<th style="text-align:center;">AWARDED TO</th></tr></thead>'
			. '<tbody><tr>' . $cells . '<td style="vertical-align:top;"><div style="height:120px;">&nbsp;</div></td></tr></tbody>'
			. '</table>';

		// Attachments / Additional instruction (same as PR)
		$attachments = '<table width="100%" border="1" cellspacing="0" cellpadding="6" style="margin-top:6px;">'
			. '<tr><td style="width:18%;">Attachments / Additional instruction:</td><td>&nbsp;</td></tr>'
			. '</table>';

		// Signatures similar to PR (Requisition / Noted / Approved for Purchase)
		$sign = '<table width="100%" border="1" cellspacing="0" cellpadding="10" style="margin-top:6px;">'
			. '<tr>'
			. '<td style="width:33%;vertical-align:bottom;"><div style="height:38px;"></div><div style="border-top:1px solid #999;text-align:center;padding-top:6px;">Requisition By:</div></td>'
			. '<td style="width:33%;vertical-align:bottom;"><div style="height:38px;"></div><div style="border-top:1px solid #999;text-align:center;padding-top:6px;">Noted By:</div></td>'
			. '<td style="width:34%;vertical-align:bottom;"><div style="height:38px;"></div><div style="border-top:1px solid #999;text-align:center;padding-top:6px;">Approved for Purchase:</div></td>'
			. '</tr>'
			. '<tr>'
			. '<td style="text-align:center;">Date</td>'
			. '<td style="text-align:center;">Date</td>'
			. '<td style="text-align:center;">Date</td>'
			. '</tr>'
			. '</table>';

		$distribution = '<div style="margin-top:6px;font-size:9px;display:flex;justify-content:space-between;">'
			. '<div>Distribution: ORIGINAL - Administrator</div>'
			. '<div>Duplicate: Requesting Section</div>'
			. '</div>';

		$html = $logoHtml . $topTitle . $revRow . $titleRow . $itemsTable . $canvassing . $attachments . $sign . $distribution;
		$mpdf->WriteHTML($html);
		$mpdf->Output($filePath, 'F');
	}
}
