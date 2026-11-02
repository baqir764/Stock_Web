Attribute VB_Name = "modAAIWOSetup"
Option Compare Database
Option Explicit

' ============================================================================
' AAIWO Stock Management - Access UI Setup
' ----------------------------------------------------------------------------
' Import this module once (Database Tools > Visual Basic > File > Import File),
' then run RunAllSetup from the Immediate window (Ctrl+G):
'
' RunAllSetup
'
' It builds every Query, Form, Report and the Switchboard on top of the tables
' and relationships that are already in this database. It is safe to re-run:
' queries are always refreshed, forms/reports/the switchboard are only created
' if they don't already exist (so any customizing you do afterwards is not
' overwritten by a second run).
'
' See "AAIWO Stock Management - Setup Instructions.md" for full walkthrough,
' including the one Trust Center setting needed for the stock-availability
' check on release lines.
' ============================================================================

Public CurrentUserID As Long
Public CurrentUserName As String

' ----------------------------------------------------------------------------
' Entry point
' ----------------------------------------------------------------------------
Sub RunAllSetup()
    Dim report As String
    On Error GoTo ErrHandler

    DoCmd.Echo False
    DoCmd.SetWarnings False

    Call Setup_Queries
    report = report & "Queries: done." & vbCrLf

    Call Setup_Forms
    report = report & "Forms: done." & vbCrLf

    Call Setup_Reports
    report = report & "Reports: done." & vbCrLf

    Call Setup_Switchboard
    report = report & "Switchboard: done." & vbCrLf

    Call Setup_StartupOptions
    report = report & "Startup options: done." & vbCrLf

    Dim vbaOK As Boolean
    vbaOK = Setup_ReleaseValidationCode()
    If vbaOK Then
        report = report & "Release stock-availability check: installed." & vbCrLf
    Else
    report = report & "Release stock-availability check: SKIPPED (needs " & _
    "'Trust access to the VBA project object model' - see instructions)." & vbCrLf
End If

DoCmd.SetWarnings True
DoCmd.Echo True

MsgBox "AAIWO Stock Management setup finished." & vbCrLf & vbCrLf & report & _
vbCrLf & "Close and reopen the database, or just open frmSwitchboard, to get started.", _
vbInformation, "Setup complete"
Exit Sub

ErrHandler:
    DoCmd.SetWarnings True
    DoCmd.Echo True
    MsgBox "Setup stopped with an error and did not finish." & vbCrLf & vbCrLf & _
    "Error " & Err.Number & ": " & Err.Description & vbCrLf & vbCrLf & _
    "Progress so far:" & vbCrLf & report & vbCrLf & _
    "Fix the issue if you can tell what it is, then run RunAllSetup again - " & _
    "it safely skips anything already built.", vbCritical, "Setup error"
End Sub

' ----------------------------------------------------------------------------
' Existence helpers (idempotency)
' ----------------------------------------------------------------------------
Function FormExists(nm As String) As Boolean
    Dim obj As AccessObject, cnt As Object
    Set cnt = CurrentProject.AllForms
    On Error Resume Next
    FormExists = False
    For Each obj In cnt
        If obj.Name = nm Then
            FormExists = True
            Exit For
        End If
    Next obj
End Function

Function ReportExists(nm As String) As Boolean
    Dim obj As AccessObject, cnt As Object
    Set cnt = CurrentProject.AllReports
    ReportExists = False
    For Each obj In cnt
        If obj.Name = nm Then
            ReportExists = True
            Exit For
        End If
    Next obj
End Function

Sub DropQueryIfExists(nm As String)
    On Error Resume Next
    CurrentDb.QueryDefs.Delete nm
    On Error GoTo 0
End Sub

Sub SaveAndRenameForm(tempName As String, finalName As String)
    DoCmd.Close acForm, tempName, acSaveYes
    On Error Resume Next
    DoCmd.DeleteObject acForm, finalName
    On Error GoTo 0
    DoCmd.Rename finalName, acForm, tempName
End Sub

Sub SaveAndRenameReport(tempName As String, finalName As String)
    DoCmd.Close acReport, tempName, acSaveYes
    On Error Resume Next
    DoCmd.DeleteObject acReport, finalName
    On Error GoTo 0
    DoCmd.Rename finalName, acReport, tempName
End Sub

' ----------------------------------------------------------------------------
' Shared helpers used by generated form/report code and by the UI itself
' ----------------------------------------------------------------------------
Public Function NextID(tbl As String, fld As String) As Long
    NextID = Nz(DMax(fld, tbl), 0) + 1
End Function

' Called from a form's BeforeInsert property (bound as an expression, e.g.
' =AutoID("Items","ItemID")) - Access supports binding an expression like this
' directly on most event properties without needing VBA code-behind.
Public Function AutoID(tbl As String, fld As String) As Variant
    On Error Resume Next
    Screen.ActiveForm.Controls(fld) = NextID(tbl, fld)
End Function

Public Function StampReceiptAudit() As Variant
    Dim frm As Form
    Set frm = Screen.ActiveForm
    On Error Resume Next
    If frm.NewRecord Then
        frm!CreatedByUserID = IIf(CurrentUserID = 0, Null, CurrentUserID)
        frm!CreatedAt = Now
    End If
    frm!UpdatedByUserID = IIf(CurrentUserID = 0, Null, CurrentUserID)
    frm!UpdatedAt = Now
End Function

Public Function StampReleaseAudit() As Variant
    Dim frm As Form
    Set frm = Screen.ActiveForm
    On Error Resume Next
    If frm.NewRecord Then
        frm!CreatedByUserID = IIf(CurrentUserID = 0, Null, CurrentUserID)
        frm!CreatedAt = Now
    End If
    frm!UpdatedByUserID = IIf(CurrentUserID = 0, Null, CurrentUserID)
    frm!UpdatedAt = Now
End Function

' Switchboard buttons call this via an expression like =OpenObj("F","frmItems")
Public Function OpenObj(kind As String, nm As String) As Variant
    Select Case kind
    Case "F": DoCmd.OpenForm nm
    Case "R": DoCmd.OpenReport nm, acViewPreview
    Case "Q": DoCmd.OpenQuery nm, acViewNormal
    End Select
End Function

' frmSwitchboard's On Open calls =SetCurrentUser() so records created in this
' session can be attributed to somebody. This is attribution only, not a
' security login - Access has no built-in way to verify the bcrypt password
' hashes carried over from the web app. See the setup instructions.
Public Function SetCurrentUser() As Variant
    Dim uname As String, uid As Variant
    uname = Trim(Nz(InputBox("Who is using the database right now?" & vbCrLf & _
    "Type your username (used only to tag records you create/edit - this is not a security login).", _
    "AAIWO Stock Management"), ""))
    If uname = "" Then
        CurrentUserID = 0
        CurrentUserName = ""
        Exit Function
    End If
    uid = DLookup("UserID", "Users", "Username = " & Quote(uname) & " AND Active = True")
    If IsNull(uid) Then
        MsgBox "No active user account found for '" & uname & "'. Records will be saved without attribution." & _
        vbCrLf & "An admin can add accounts via the Users form.", vbExclamation
        CurrentUserID = 0
        CurrentUserName = uname
    Else
    CurrentUserID = uid
    CurrentUserName = uname
End If
End Function

Function Quote(s As String) As String
    Quote = "'" & Replace(s, "'", "''") & "'"
End Function

' ============================================================================
' QUERIES
' ============================================================================
Sub Setup_Queries()
    Dim db As DAO.Database
    Set db = CurrentDb

    DropQueryIfExists "qryStockBalance"
    db.CreateQueryDef "qryStockBalance", _
    "SELECT i.ItemID, i.ItemName, i.ItemNameLocal, i.Description, i.Unit, i.LowStockLevel," & _
    " Nz((SELECT SUM(Quantity) FROM StockReceiptDetails WHERE ItemID = i.ItemID),0) AS TotalReceived," & _
    " Nz((SELECT SUM(Quantity) FROM StockReleaseDetails WHERE ItemID = i.ItemID),0) AS TotalReleased," & _
    " Nz((SELECT SUM(Quantity) FROM StockReceiptDetails WHERE ItemID = i.ItemID),0)" & _
    " - Nz((SELECT SUM(Quantity) FROM StockReleaseDetails WHERE ItemID = i.ItemID),0) AS CurrentBalance" & _
    " FROM Items AS i" & _
    " ORDER BY i.ItemID;"

    DropQueryIfExists "qryLowStock"
    db.CreateQueryDef "qryLowStock", _
    "SELECT * FROM qryStockBalance" & _
    " WHERE LowStockLevel Is Not Null AND CurrentBalance <= LowStockLevel" & _
    " ORDER BY ItemName;"

    DropQueryIfExists "qryHealthFacilitiesList"
    db.CreateQueryDef "qryHealthFacilitiesList", _
    "SELECT hf.HFID, hf.HFName, hf.DistrictID, d.DistrictName, hf.HFType, hf.DistributionDate, hf.InChargePerson" & _
    " FROM HealthFacilities AS hf LEFT JOIN Districts AS d ON hf.DistrictID = d.DistrictID" & _
    " ORDER BY hf.HFType, d.DistrictName, hf.HFName;"

    DropQueryIfExists "qryReceiptLines"
    db.CreateQueryDef "qryReceiptLines", _
    "SELECT r.ReceiptID, r.GRNNumber, r.ReceiptDate, r.Supplier, r.GroupName," & _
    " d.ReceiptDetailID, d.ItemID, i.ItemName, i.ItemNameLocal, i.Description, i.Unit, d.Quantity" & _
    " FROM (StockReceipts AS r INNER JOIN StockReceiptDetails AS d ON r.ReceiptID = d.ReceiptID)" & _
    " INNER JOIN Items AS i ON d.ItemID = i.ItemID" & _
    " ORDER BY r.ReceiptID, i.ItemName;"

    DropQueryIfExists "qryReleaseLines"
    db.CreateQueryDef "qryReleaseLines", _
    "SELECT rl.ReleaseID, rl.StockCardNo, rl.ReleaseDate, rl.ReceiverName, rl.StorekeeperName, rl.GroupName," & _
    " hf.HFName, dd.DistrictName," & _
    " d.ReleaseDetailID, d.ItemID, i.ItemName, i.ItemNameLocal, i.Description, i.Unit, d.Quantity" & _
    " FROM ((StockReleases AS rl INNER JOIN StockReleaseDetails AS d ON rl.ReleaseID = d.ReleaseID)" & _
    " INNER JOIN Items AS i ON d.ItemID = i.ItemID)" & _
    " LEFT JOIN (HealthFacilities AS hf LEFT JOIN Districts AS dd ON hf.DistrictID = dd.DistrictID) ON rl.HFID = hf.HFID" & _
    " ORDER BY rl.ReleaseID, i.ItemName;"

    DropQueryIfExists "qryReleasesByFacility"
    db.CreateQueryDef "qryReleasesByFacility", _
    "SELECT hf.HFName, d.DistrictName, COUNT(rl.ReleaseID) AS CardCount" & _
    " FROM (StockReleases AS rl LEFT JOIN HealthFacilities AS hf ON rl.HFID = hf.HFID)" & _
    " LEFT JOIN Districts AS d ON hf.DistrictID = d.DistrictID" & _
    " GROUP BY hf.HFName, d.DistrictName" & _
    " ORDER BY COUNT(rl.ReleaseID) DESC;"
End Sub

' ============================================================================
' FORMS
' ============================================================================
Sub Setup_Forms()
    Call Build_frmDistricts
    Call Build_frmItems
    Call Build_frmHealthFacilities
    Call Build_frmUsers
    Call Build_frmStockReceiptDetailsSub
    Call Build_frmStockReceipts
    Call Build_frmStockReleaseDetailsSub
    Call Build_frmStockReleases
End Sub

' -- Generic continuous "editable list" builder, used for simple lookup-style
' tables (Districts, Items, Users). Each field becomes one column: a label
' in the Form Header, and a bound control in the Detail section.
' fields: array of 4-element arrays: FieldName, Caption, ControlType, WidthTwips
Function BuildListForm(finalName As String, recordSource As String, fields As Variant) As Boolean
    If FormExists(finalName) Then
        BuildListForm = False
        Exit Function
    End If

    Dim frm As Form
    Set frm = CreateForm
    frm.RecordSource = recordSource
    frm.DefaultView = 1 ' Continuous Forms
    frm.AllowAdditions = True
    frm.AllowDeletions = True
    frm.AllowEdits = True
    frm.NavigationButtons = True
    frm.DividingLines = True
    frm.HasModule = False

    Dim leftPos As Long
    Dim i As Integer
    Dim rowHeight As Long
    rowHeight = 320

    leftPos = 60
    For i = 0 To UBound(fields)
        Dim fName As String, fCap As String, fType As Integer, fWidth As Long
        fName = fields(i)(0): fCap = fields(i)(1): fType = fields(i)(2): fWidth = fields(i)(3)

        Dim lbl As Control
        Set lbl = CreateControl(frm.Name, acLabel, acHeader, , , leftPos, 60, fWidth, 260)
        lbl.Caption = fCap
        lbl.FontBold = True
        lbl.FontSize = 8

        Dim ctl As Control
        Set ctl = CreateControl(frm.Name, fType, acDetail, , fName, leftPos, 20, fWidth, rowHeight - 40)
        If fType = acComboBox Then
            ctl.LimitToList = False
        End If

        leftPos = leftPos + fWidth + 100
    Next i

    frm.Section(acDetail).Height = rowHeight
    frm.Section(acHeader).Height = 340
    frm.Width = leftPos + 200

    SaveAndRenameForm frm.Name, finalName
    BuildListForm = True
End Function

Sub Build_frmDistricts()
    Dim fields(1) As Variant
    fields(0) = Array("DistrictID", "ID", acTextBox, 700)
    fields(1) = Array("DistrictName", "District Name", acTextBox, 3200)
    If BuildListForm("frmDistricts", "Districts", fields) Then
        InjectBeforeInsertAutoID "frmDistricts", "Districts", "DistrictID"
    End If
End Sub

Sub Build_frmItems()
    Dim fields(6) As Variant
    fields(0) = Array("ItemID", "ID", acTextBox, 500)
    fields(1) = Array("ItemName", "Item Name", acTextBox, 2200)
    fields(2) = Array("ItemNameLocal", "Item Name (Dari)", acTextBox, 2200)
    fields(3) = Array("Description", "Description", acTextBox, 2600)
    fields(4) = Array("Unit", "Unit", acTextBox, 900)
    fields(5) = Array("OpeningQtyIn", "Opening In", acTextBox, 1100)
    fields(6) = Array("LowStockLevel", "Low Stock Level", acTextBox, 1300)
    If BuildListForm("frmItems", "Items", fields) Then
        InjectBeforeInsertAutoID "frmItems", "Items", "ItemID"
    End If
End Sub

Sub Build_frmUsers()
    Dim fields(4) As Variant
    fields(0) = Array("Username", "Username", acTextBox, 1600)
    fields(1) = Array("FullName", "Full Name", acTextBox, 2200)
    fields(2) = Array("Role", "Role", acComboBox, 1300)
    fields(3) = Array("Active", "Active", acCheckBox, 700)
    fields(4) = Array("PasswordHash", "Password Hash (from web app - cannot be reset here)", acTextBox, 3600)
    If BuildListForm("frmUsers", "Users", fields) Then
        ' Role combo: plain value list
        SetComboValueList "frmUsers", "Role", "staff;admin"
        InjectBeforeInsertAutoID "frmUsers", "Users", "UserID"
        LockControl "frmUsers", "PasswordHash"
    End If
End Sub

Sub Build_frmHealthFacilities()
    Dim fields(4) As Variant
    fields(0) = Array("HFID", "ID", acTextBox, 500)
    fields(1) = Array("HFName", "Facility Name", acTextBox, 2600)
    fields(2) = Array("DistrictID", "District", acComboBox, 2200)
    fields(3) = Array("HFType", "Type", acComboBox, 1000)
    fields(4) = Array("InChargePerson", "In-Charge Person", acTextBox, 2400)
    If BuildListForm("frmHealthFacilities", "HealthFacilities", fields) Then
        SetComboRowSource "frmHealthFacilities", "DistrictID", _
        "SELECT DistrictID, DistrictName FROM Districts ORDER BY DistrictName", 2, "0in;1.6in", 1
        SetComboValueList "frmHealthFacilities", "HFType", "(BHC);(CHC);(SHC);(DH);(H3);(PO)"
        InjectBeforeInsertAutoID "frmHealthFacilities", "HealthFacilities", "HFID"
    End If
End Sub

' -- Subform: Stock Receipt line items (continuous, embedded in frmStockReceipts)
Sub Build_frmStockReceiptDetailsSub()
    If FormExists("frmStockReceiptDetailsSub") Then Exit Sub

    Dim frm As Form
    Set frm = CreateForm
    frm.RecordSource = "StockReceiptDetails"
    frm.DefaultView = 1
    frm.NavigationButtons = False
    frm.AllowAdditions = True
    frm.AllowDeletions = True

    Dim c1 As Control, c2 As Control
    Set c1 = CreateControl(frm.Name, acLabel, acHeader, , , 60, 60, 2600, 260)
    c1.Caption = "Item"
    c1.FontBold = True: c1.FontSize = 8
    Set c1 = CreateControl(frm.Name, acLabel, acHeader, , , 2760, 60, 1200, 260)
    c1.Caption = "Quantity"
    c1.FontBold = True: c1.FontSize = 8

    Set c2 = CreateControl(frm.Name, acComboBox, acDetail, , "ItemID", 60, 20, 2600, 280)
    c2.LimitToList = False
    Set c2 = CreateControl(frm.Name, acTextBox, acDetail, , "Quantity", 2760, 20, 1200, 280)

    frm.Section(acDetail).Height = 320
    frm.Section(acHeader).Height = 340
    frm.Width = 4200

    SaveAndRenameForm frm.Name, "frmStockReceiptDetailsSub"

    SetComboRowSource "frmStockReceiptDetailsSub", "ItemID", _
    "SELECT ItemID, ItemName, Unit FROM Items ORDER BY ItemName", 3, "0in;2in;0.5in", 1
    InjectBeforeInsertAutoID "frmStockReceiptDetailsSub", "StockReceiptDetails", "ReceiptDetailID"
End Sub

' -- Main form: Stock Receipts (GRN), single form + embedded line-items subform
Sub Build_frmStockReceipts()
    If FormExists("frmStockReceipts") Then Exit Sub

    Dim frm As Form
    Set frm = CreateForm
    frm.RecordSource = "StockReceipts"
    frm.DefaultView = 0 ' Single Form
    frm.Caption = "Stock In - GRN (Goods Received Note)"

    AddLabeledField frm.Name, "GRNNumber", "GRN Number", 60, acTextBox, 2400
    AddLabeledField frm.Name, "ReceiptDate", "Receipt Date", 460, acTextBox, 2400
    AddLabeledField frm.Name, "Supplier", "Supplier", 860, acTextBox, 3200
    AddLabeledField frm.Name, "GroupName", "Group", 1260, acTextBox, 2000

    Dim sub1 As Control
    Set sub1 = CreateControl(frm.Name, acSubform, acDetail, , , 60, 1750, 6200, 2600)
    sub1.Name = "sfrmReceiptDetails"
    sub1.SourceObject = "frmStockReceiptDetailsSub"
    sub1.LinkChildFields = "ReceiptID"
    sub1.LinkMasterFields = "ReceiptID"

    Dim lbl As Control
    Set lbl = CreateControl(frm.Name, acLabel, acDetail, , , 60, 1550, 3000, 220)
    lbl.Caption = "Items received:"
    lbl.FontBold = True

    ' Audit fields - visible but locked (informational only)
    AddLabeledField frm.Name, "CreatedByUserID", "Created By (UserID)", 4470, acTextBox, 1200
    AddLabeledField frm.Name, "CreatedAt", "Created At", 4870, acTextBox, 2000
    AddLabeledField frm.Name, "UpdatedByUserID", "Updated By (UserID)", 5270, acTextBox, 1200
    AddLabeledField frm.Name, "UpdatedAt", "Updated At", 5670, acTextBox, 2000

    frm.Section(acDetail).Height = 6100
    frm.Width = 6500

    SaveAndRenameForm frm.Name, "frmStockReceipts"

    InjectBeforeInsertAutoID "frmStockReceipts", "StockReceipts", "ReceiptID"
    InjectBeforeUpdateExpr "frmStockReceipts", "=StampReceiptAudit()"
    LockControl "frmStockReceipts", "CreatedByUserID"
    LockControl "frmStockReceipts", "CreatedAt"
    LockControl "frmStockReceipts", "UpdatedByUserID"
    LockControl "frmStockReceipts", "UpdatedAt"
End Sub

' -- Subform: Stock Release line items (continuous, embedded in frmStockReleases)
Sub Build_frmStockReleaseDetailsSub()
    If FormExists("frmStockReleaseDetailsSub") Then Exit Sub

    Dim frm As Form
    Set frm = CreateForm
    frm.RecordSource = "StockReleaseDetails"
    frm.DefaultView = 1
    frm.HasModule = True ' needed so the stock-availability check can be added later
    frm.NavigationButtons = False
    frm.AllowAdditions = True
    frm.AllowDeletions = True

    Dim c1 As Control, c2 As Control
    Set c1 = CreateControl(frm.Name, acLabel, acHeader, , , 60, 60, 2600, 260)
    c1.Caption = "Item"
    c1.FontBold = True: c1.FontSize = 8
    Set c1 = CreateControl(frm.Name, acLabel, acHeader, , , 2760, 60, 1200, 260)
    c1.Caption = "Quantity"
    c1.FontBold = True: c1.FontSize = 8

    Set c2 = CreateControl(frm.Name, acComboBox, acDetail, , "ItemID", 60, 20, 2600, 280)
    c2.LimitToList = False
    Set c2 = CreateControl(frm.Name, acTextBox, acDetail, , "Quantity", 2760, 20, 1200, 280)

    frm.Section(acDetail).Height = 320
    frm.Section(acHeader).Height = 340
    frm.Width = 4200

    SaveAndRenameForm frm.Name, "frmStockReleaseDetailsSub"

    SetComboRowSource "frmStockReleaseDetailsSub", "ItemID", _
    "SELECT ItemID, ItemName, Unit FROM Items ORDER BY ItemName", 3, "0in;2in;0.5in", 1
    InjectBeforeInsertAutoID "frmStockReleaseDetailsSub", "StockReleaseDetails", "ReleaseDetailID"
End Sub

' -- Main form: Stock Releases (Stock Card), single form + embedded subform
Sub Build_frmStockReleases()
    If FormExists("frmStockReleases") Then Exit Sub

    Dim frm As Form
    Set frm = CreateForm
    frm.RecordSource = "StockReleases"
    frm.DefaultView = 0
    frm.Caption = "Stock Out - Stock Release Card"

    AddLabeledField frm.Name, "StockCardNo", "Stock Card No.", 60, acTextBox, 2600
    AddLabeledField frm.Name, "ReleaseDate", "Release Date", 460, acTextBox, 2000
    AddLabeledField frm.Name, "GroupName", "Group", 860, acTextBox, 1800
    AddLabeledField frm.Name, "HFID", "Receiving Facility", 1260, acComboBox, 3600
    AddLabeledField frm.Name, "ReceiverName", "Receiver Name", 1660, acTextBox, 3200
    AddLabeledField frm.Name, "StorekeeperName", "Storekeeper", 2060, acTextBox, 3200

    Dim sub1 As Control
    Set sub1 = CreateControl(frm.Name, acSubform, acDetail, , , 60, 2750, 6200, 2600)
    sub1.Name = "sfrmReleaseDetails"
    sub1.SourceObject = "frmStockReleaseDetailsSub"
    sub1.LinkChildFields = "ReleaseID"
    sub1.LinkMasterFields = "ReleaseID"

    Dim lbl As Control
    Set lbl = CreateControl(frm.Name, acLabel, acDetail, , , 60, 2550, 3000, 220)
    lbl.Caption = "Items issued (cannot exceed available balance):"
    lbl.FontBold = True

    AddLabeledField frm.Name, "CreatedByUserID", "Created By (UserID)", 5470, acTextBox, 1200
    AddLabeledField frm.Name, "CreatedAt", "Created At", 5870, acTextBox, 2000
    AddLabeledField frm.Name, "UpdatedByUserID", "Updated By (UserID)", 6270, acTextBox, 1200
    AddLabeledField frm.Name, "UpdatedAt", "Updated At", 6670, acTextBox, 2000

    frm.Section(acDetail).Height = 7100
    frm.Width = 6500

    SaveAndRenameForm frm.Name, "frmStockReleases"

    SetComboRowSource "frmStockReleases", "HFID", _
    "SELECT hf.HFID, hf.HFName, d.DistrictName FROM HealthFacilities hf LEFT JOIN Districts d ON hf.DistrictID = d.DistrictID ORDER BY hf.HFName", _
    3, "0in;2in;1.5in", 1

    InjectBeforeInsertAutoID "frmStockReleases", "StockReleases", "ReleaseID"
    InjectBeforeUpdateExpr "frmStockReleases", "=StampReleaseAudit()"
    LockControl "frmStockReleases", "CreatedByUserID"
    LockControl "frmStockReleases", "CreatedAt"
    LockControl "frmStockReleases", "UpdatedByUserID"
    LockControl "frmStockReleases", "UpdatedAt"
End Sub

' -- Small layout helper for single-form (label above/left, one per row)
Sub AddLabeledField(frmName As String, fieldName As String, caption As String, top As Long, _
    ctrlType As Integer, widthTw As Long)
    Dim lbl As Control, ctl As Control
    Set lbl = CreateControl(frmName, acLabel, acDetail, , , 60, top, 1500, 260)
    lbl.Caption = caption
    Set ctl = CreateControl(frmName, ctrlType, acDetail, , fieldName, 1620, top, widthTw, 280)
    If ctrlType = acComboBox Then ctl.LimitToList = False
End Sub

Sub SetComboRowSource(frmName As String, ctlName As String, sql As String, colCount As Integer, _
    colWidths As String, boundCol As Integer)
    Dim frm As Form
    DoCmd.OpenForm frmName, acDesign, , , , acHidden
    Set frm = Forms(frmName)
    With frm.Controls(ctlName)
        .RowSourceType = "Table/Query"
        .RowSource = sql
        .ColumnCount = colCount
        .ColumnWidths = colWidths
        .BoundColumn = boundCol
    End With
    DoCmd.Close acForm, frmName, acSaveYes
End Sub

Sub SetComboValueList(frmName As String, ctlName As String, valueList As String)
    Dim frm As Form
    DoCmd.OpenForm frmName, acDesign, , , , acHidden
    Set frm = Forms(frmName)
    With frm.Controls(ctlName)
        .RowSourceType = "Value List"
        .RowSource = valueList
        .ColumnCount = 1
    End With
    DoCmd.Close acForm, frmName, acSaveYes
End Sub

Sub LockControl(frmName As String, ctlName As String)
    Dim frm As Form
    DoCmd.OpenForm frmName, acDesign, , , , acHidden
    Set frm = Forms(frmName)
    frm.Controls(ctlName).Locked = True
    frm.Controls(ctlName).Enabled = True
    DoCmd.Close acForm, frmName, acSaveYes
End Sub

' Binds BeforeInsert to =AutoID("Table","Field") as a plain expression -
' no VBA code-behind required, works even without VBA project trust enabled.
Sub InjectBeforeInsertAutoID(frmName As String, tbl As String, fld As String)
    Dim frm As Form
    DoCmd.OpenForm frmName, acDesign, , , , acHidden
    Set frm = Forms(frmName)
    frm.BeforeInsert = "=AutoID(" & Chr(34) & tbl & Chr(34) & "," & Chr(34) & fld & Chr(34) & ")"
    DoCmd.Close acForm, frmName, acSaveYes
End Sub

' Binds a form's BeforeUpdate to a plain expression, e.g. =StampReceiptAudit()
' - same no-VBA-trust-needed technique as InjectBeforeInsertAutoID.
Sub InjectBeforeUpdateExpr(frmName As String, exprCall As String)
    Dim frm As Form
    DoCmd.OpenForm frmName, acDesign, , , , acHidden
    Set frm = Forms(frmName)
    frm.BeforeUpdate = exprCall
    DoCmd.Close acForm, frmName, acSaveYes
End Sub

' ============================================================================
' REPORTS
' ============================================================================
Sub Setup_Reports()
    Call Build_rptStockBalance
    Call Build_rptReleasesByFacility
    Call Build_rptGRN
    Call Build_rptStockCard
End Sub

Sub Build_rptStockBalance()
    If ReportExists("rptStockBalance") Then Exit Sub

    Dim rpt As Report
    Set rpt = CreateReport
    rpt.RecordSource = "qryStockBalance"
    rpt.Caption = "Stock Balance"

    Dim titleLbl As Control
    Set titleLbl = CreateReportControl(rpt.Name, acLabel, acHeader, , , 60, 60, 6500, 340)
    titleLbl.Caption = "AAIWO - Stock Balance (Received vs. Released)"
    titleLbl.FontBold = True
    titleLbl.FontSize = 14

    Dim cols(5) As Variant
    cols(0) = Array("ItemID", "ID", 500)
    cols(1) = Array("ItemName", "Item", 2400)
    cols(2) = Array("Unit", "Unit", 700)
    cols(3) = Array("TotalReceived", "Received", 1100)
    cols(4) = Array("TotalReleased", "Released", 1100)
    cols(5) = Array("CurrentBalance", "Balance", 1100)

    Dim leftPos As Long, i As Integer
    leftPos = 60
    For i = 0 To UBound(cols)
        Dim h As Control, ctl As Control
        Set h = CreateReportControl(rpt.Name, acLabel, acPageHeader, , , leftPos, 60, cols(i)(2), 260)
        h.Caption = cols(i)(1)
        h.FontBold = True
        Set ctl = CreateReportControl(rpt.Name, acTextBox, acDetail, , cols(i)(0), leftPos, 20, cols(i)(2), 240)
        leftPos = leftPos + cols(i)(2) + 100
    Next i

    rpt.Section(acPageHeader).Height = 340
    rpt.Section(acDetail).Height = 280
    rpt.Width = leftPos + 200

    SaveAndRenameReport rpt.Name, "rptStockBalance"
End Sub

Sub Build_rptReleasesByFacility()
    If ReportExists("rptReleasesByFacility") Then Exit Sub

    Dim rpt As Report
    Set rpt = CreateReport
    rpt.RecordSource = "qryReleasesByFacility"
    rpt.Caption = "Releases by Facility"

    Dim titleLbl As Control
    Set titleLbl = CreateReportControl(rpt.Name, acLabel, acHeader, , , 60, 60, 6500, 340)
    titleLbl.Caption = "AAIWO - Stock Cards Issued, by Health Facility"
    titleLbl.FontBold = True
    titleLbl.FontSize = 14

    Dim cols(2) As Variant
    cols(0) = Array("HFName", "Facility", 3200)
    cols(1) = Array("DistrictName", "District", 2200)
    cols(2) = Array("CardCount", "Stock Cards", 1400)

    Dim leftPos As Long, i As Integer
    leftPos = 60
    For i = 0 To UBound(cols)
        Dim h As Control, ctl As Control
        Set h = CreateReportControl(rpt.Name, acLabel, acPageHeader, , , leftPos, 60, cols(i)(2), 260)
        h.Caption = cols(i)(1)
        h.FontBold = True
        Set ctl = CreateReportControl(rpt.Name, acTextBox, acDetail, , cols(i)(0), leftPos, 20, cols(i)(2), 240)
        leftPos = leftPos + cols(i)(2) + 100
    Next i

    rpt.Section(acPageHeader).Height = 340
    rpt.Section(acDetail).Height = 280
    rpt.Width = leftPos + 200

    SaveAndRenameReport rpt.Name, "rptReleasesByFacility"
End Sub

' -- Printable GRN slip, grouped by ReceiptID (open filtered to one ReceiptID,
' or run over all GRNs - each starts a new page).
Sub Build_rptGRN()
    If ReportExists("rptGRN") Then Exit Sub

    Dim rpt As Report
    Set rpt = CreateReport
    rpt.RecordSource = "qryReceiptLines"
    rpt.Caption = "GRN (Goods Received Note)"

    Dim grpIdx As Integer
    grpIdx = CreateGroupLevel(rpt.Name, "ReceiptID", True, False)
    rpt.Section(acGroupLevel1Header).ForceNewPage = 1 ' 1 = Before Section - new page before each GRN

    Dim lbl As Control, ctl As Control

    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 60, 40, 6200, 260)
    lbl.Caption = "AAIWO - Afghan Alfouz Innovational Welfare Organization"
    lbl.FontBold = True: lbl.FontSize = 12
    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 60, 320, 6200, 300)
    lbl.Caption = "Goods Received Note (GRN)"
    lbl.FontBold = True: lbl.FontSize = 16

    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 60, 780, 1300, 240)
    lbl.Caption = "GRN Number:"
    Set ctl = CreateReportControl(rpt.Name, acTextBox, acGroupLevel1Header, , "GRNNumber", 1400, 780, 2000, 240)
    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 3500, 780, 700, 240)
    lbl.Caption = "Date:"
    Set ctl = CreateReportControl(rpt.Name, acTextBox, acGroupLevel1Header, , "ReceiptDate", 4250, 780, 1800, 240)

    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 60, 1060, 1300, 240)
    lbl.Caption = "Supplier:"
    Set ctl = CreateReportControl(rpt.Name, acTextBox, acGroupLevel1Header, , "Supplier", 1400, 1060, 3000, 240)

    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 60, 1420, 1800, 240)
    lbl.Caption = "Item Name"
    lbl.FontBold = True
    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 1900, 1420, 1500, 240)
    lbl.Caption = "Dari Name"
    lbl.FontBold = True
    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 3440, 1420, 1800, 240)
    lbl.Caption = "Description"
    lbl.FontBold = True
    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 5280, 1420, 600, 240)
    lbl.Caption = "Unit"
    lbl.FontBold = True
    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 5920, 1420, 700, 240)
    lbl.Caption = "Qty"
    lbl.FontBold = True

    Set ctl = CreateReportControl(rpt.Name, acTextBox, acDetail, , "ItemName", 60, 20, 1800, 240)
    Set ctl = CreateReportControl(rpt.Name, acTextBox, acDetail, , "ItemNameLocal", 1900, 20, 1500, 240)
    Set ctl = CreateReportControl(rpt.Name, acTextBox, acDetail, , "Description", 3440, 20, 1800, 240)
    Set ctl = CreateReportControl(rpt.Name, acTextBox, acDetail, , "Unit", 5280, 20, 600, 240)
    Set ctl = CreateReportControl(rpt.Name, acTextBox, acDetail, , "Quantity", 5920, 20, 700, 240)

    Set lbl = CreateReportControl(rpt.Name, acLabel, acPageFooter, , , 60, 40, 6200, 260)
    lbl.Caption = "Delivered by: ______________________ Received by: ______________________"

    rpt.Section(acGroupLevel1Header).Height = 1680
    rpt.Section(acDetail).Height = 280
    rpt.Section(acPageFooter).Height = 340
    rpt.Width = 6800

    SaveAndRenameReport rpt.Name, "rptGRN"
End Sub

' -- Printable Stock Release Card, grouped by ReleaseID
Sub Build_rptStockCard()
    If ReportExists("rptStockCard") Then Exit Sub

    Dim rpt As Report
    Set rpt = CreateReport
    rpt.RecordSource = "qryReleaseLines"
    rpt.Caption = "Stock Release Card"

    Dim grpIdx As Integer
    grpIdx = CreateGroupLevel(rpt.Name, "ReleaseID", True, False)
    rpt.Section(acGroupLevel1Header).ForceNewPage = 1 ' 1 = Before Section - new page before each stock card

    Dim lbl As Control, ctl As Control

    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 60, 40, 6200, 260)
    lbl.Caption = "AAIWO - Afghan Alfouz Innovational Welfare Organization"
    lbl.FontBold = True: lbl.FontSize = 12
    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 60, 320, 6200, 300)
    lbl.Caption = "Stock Release Card"
    lbl.FontBold = True: lbl.FontSize = 16

    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 60, 780, 1300, 240)
    lbl.Caption = "Stock Card No:"
    Set ctl = CreateReportControl(rpt.Name, acTextBox, acGroupLevel1Header, , "StockCardNo", 1400, 780, 2000, 240)
    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 3500, 780, 700, 240)
    lbl.Caption = "Date:"
    Set ctl = CreateReportControl(rpt.Name, acTextBox, acGroupLevel1Header, , "ReleaseDate", 4250, 780, 1800, 240)

    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 60, 1060, 1300, 240)
    lbl.Caption = "Facility:"
    Set ctl = CreateReportControl(rpt.Name, acTextBox, acGroupLevel1Header, , "HFName", 1400, 1060, 2000, 240)
    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 3500, 1060, 700, 240)
    lbl.Caption = "District:"
    Set ctl = CreateReportControl(rpt.Name, acTextBox, acGroupLevel1Header, , "DistrictName", 4250, 1060, 1800, 240)

    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 60, 1340, 1300, 240)
    lbl.Caption = "Receiver:"
    Set ctl = CreateReportControl(rpt.Name, acTextBox, acGroupLevel1Header, , "ReceiverName", 1400, 1340, 3000, 240)

    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 60, 1700, 1800, 240)
    lbl.Caption = "Item Name"
    lbl.FontBold = True
    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 1900, 1700, 1500, 240)
    lbl.Caption = "Dari Name"
    lbl.FontBold = True
    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 3440, 1700, 1800, 240)
    lbl.Caption = "Description"
    lbl.FontBold = True
    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 5280, 1700, 600, 240)
    lbl.Caption = "Unit"
    lbl.FontBold = True
    Set lbl = CreateReportControl(rpt.Name, acLabel, acGroupLevel1Header, , , 5920, 1700, 700, 240)
    lbl.Caption = "Qty"
    lbl.FontBold = True

    Set ctl = CreateReportControl(rpt.Name, acTextBox, acDetail, , "ItemName", 60, 20, 1800, 240)
    Set ctl = CreateReportControl(rpt.Name, acTextBox, acDetail, , "ItemNameLocal", 1900, 20, 1500, 240)
    Set ctl = CreateReportControl(rpt.Name, acTextBox, acDetail, , "Description", 3440, 20, 1800, 240)
    Set ctl = CreateReportControl(rpt.Name, acTextBox, acDetail, , "Unit", 5280, 20, 600, 240)
    Set ctl = CreateReportControl(rpt.Name, acTextBox, acDetail, , "Quantity", 5920, 20, 700, 240)

    Set lbl = CreateReportControl(rpt.Name, acLabel, acPageFooter, , , 60, 40, 6200, 260)
    lbl.Caption = "Released by (Store Keeper): ____________ Received by (Facility In-charge): ____________"

    rpt.Section(acGroupLevel1Header).Height = 1960
    rpt.Section(acDetail).Height = 280
    rpt.Section(acPageFooter).Height = 340
    rpt.Width = 6800

    SaveAndRenameReport rpt.Name, "rptStockCard"
End Sub

' ============================================================================
' SWITCHBOARD
' ============================================================================
Sub Setup_Switchboard()
    If FormExists("frmSwitchboard") Then Exit Sub

    Dim frm As Form
    Set frm = CreateForm
    frm.DefaultView = 0
    frm.Caption = "AAIWO Stock Management"
    frm.NavigationButtons = False
    frm.RecordSelectors = False
    frm.ScrollBars = 0

    Dim titleLbl As Control
    Set titleLbl = CreateControl(frm.Name, acLabel, acDetail, , , 200, 150, 4200, 400)
    titleLbl.Caption = "AAIWO Stock Management"
    titleLbl.FontBold = True
    titleLbl.FontSize = 16

    Dim subtitleLbl As Control
    Set subtitleLbl = CreateControl(frm.Name, acLabel, acDetail, , , 200, 600, 4200, 300)
    subtitleLbl.Caption = "Afghan Alfouz Innovational Welfare Organization"
    subtitleLbl.FontSize = 9

    Dim buttons(8) As Variant
    buttons(0) = Array("Items", "F", "frmItems")
    buttons(1) = Array("Stock In (GRN / Receipts)", "F", "frmStockReceipts")
    buttons(2) = Array("Stock Out (Stock Cards / Releases)", "F", "frmStockReleases")
    buttons(3) = Array("Health Facilities", "F", "frmHealthFacilities")
    buttons(4) = Array("Districts", "F", "frmDistricts")
    buttons(5) = Array("Users", "F", "frmUsers")
    buttons(6) = Array("Report: Stock Balance", "R", "rptStockBalance")
    buttons(7) = Array("Report: Releases by Facility", "R", "rptReleasesByFacility")
    buttons(8) = Array("Low Stock Items (list)", "Q", "qryLowStock")

    Dim topPos As Long, i As Integer
    topPos = 1050
    For i = 0 To 8
        Dim btn As Control
        Set btn = CreateControl(frm.Name, acCommandButton, acDetail, , , 200, topPos, 3200, 380)
        btn.Caption = buttons(i)(0)
        btn.OnClick = "=OpenObj(" & Chr(34) & buttons(i)(1) & Chr(34) & "," & Chr(34) & buttons(i)(2) & Chr(34) & ")"
        topPos = topPos + 460
    Next i

    frm.Section(acDetail).Height = topPos + 200
    frm.Width = 3800

    SaveAndRenameForm frm.Name, "frmSwitchboard"

    ' Prompt for "who's using this" once per session when the switchboard opens
    DoCmd.OpenForm "frmSwitchboard", acDesign, , , , acHidden
    Forms("frmSwitchboard").OnOpen = "=SetCurrentUser()"
    DoCmd.Close acForm, "frmSwitchboard", acSaveYes
End Sub

' ============================================================================
' STARTUP OPTIONS
' ============================================================================
Sub Setup_StartupOptions()
    Dim db As DAO.Database
    Set db = CurrentDb

    SetDbProperty db, "AppTitle", dbText, "AAIWO Stock Management"
    SetDbProperty db, "StartUpForm", dbText, "frmSwitchboard"
    SetDbProperty db, "StartUpShowDBWindow", dbBoolean, False
    SetDbProperty db, "StartUpShowStatusBar", dbBoolean, True
    SetDbProperty db, "AllowShortcutMenus", dbBoolean, True
    SetDbProperty db, "AllowFullMenus", dbBoolean, True
End Sub

Sub SetDbProperty(db As DAO.Database, propName As String, propType As Integer, propValue As Variant)
    On Error Resume Next
    db.Properties(propName) = propValue
    If Err.Number <> 0 Then
        Err.Clear
        db.Properties.Append db.CreateProperty(propName, propType, propValue)
    End If
    On Error GoTo 0
End Sub

' ============================================================================
' OPTIONAL: stock-availability validation on release lines
' ----------------------------------------------------------------------------
' This is the one enhancement that needs true VBA code-behind (to be able to
' Cancel a bad save), which in turn needs "Trust access to the VBA project
' object model" enabled in Access (File > Options > Trust Center > Trust
' Center Settings > Macro Settings). If it's not enabled, this step is
' skipped and RunAllSetup still completes everything else - you just won't
' get the "can't release more than what's in stock" guard rail on the form.
' ============================================================================
Function Setup_ReleaseValidationCode() As Boolean
    On Error GoTo Fail
    Dim vbProj As Object, vbComp As Object, code As String

    Set vbProj = Application.VBE.VBProjects(1)
    Set vbComp = vbProj.VBComponents("Form_frmStockReleaseDetailsSub")

    ' Skip if already injected (idempotent)
    If vbComp.CodeModule.CountOfLines > 0 Then
        If InStr(vbComp.CodeModule.Lines(1, vbComp.CodeModule.CountOfLines), "AAIWO_STOCK_CHECK") > 0 Then
            Setup_ReleaseValidationCode = True
            Exit Function
        End If
    End If

    code = code & "' AAIWO_STOCK_CHECK - added by RunAllSetup" & vbCrLf
    code = code & "Private Sub Form_BeforeUpdate(Cancel As Integer)" & vbCrLf
    code = code & " Dim received As Double, releasedElsewhere As Double, bal As Double" & vbCrLf
    code = code & " If IsNull(Me!ItemID) Or IsNull(Me!Quantity) Then Exit Sub" & vbCrLf
    code = code & " received = Nz(DSum(""Quantity"",""StockReceiptDetails"",""ItemID="" & Me!ItemID),0)" & vbCrLf
    code = code & " releasedElsewhere = Nz(DSum(""Quantity"",""StockReleaseDetails"",""ItemID="" & Me!ItemID & "" AND ReleaseDetailID<>"" & Nz(Me!ReleaseDetailID,0)),0)" & vbCrLf
    code = code & " bal = received - releasedElsewhere" & vbCrLf
    code = code & " If Me!Quantity > bal Then" & vbCrLf
    code = code & " MsgBox ""Only "" & bal & "" available for this item. Reduce the quantity."", vbExclamation, ""Over stock limit""" & vbCrLf
    code = code & " Cancel = True" & vbCrLf
    code = code & " End If" & vbCrLf
    code = code & "End Sub" & vbCrLf

    vbComp.CodeModule.AddFromString code

    Setup_ReleaseValidationCode = True
    Exit Function
Fail:
    Setup_ReleaseValidationCode = False
End Function
