Set WshShell = CreateObject("WScript.Shell")
If Not WshShell.AppActivate("Mini Dreamweaver") Then
    WshShell.Run """C:\Program Files\Google\Chrome\Application\chrome_proxy.exe"" --profile-directory=Default --app-id=dcknllkmkdofmhjkpmgmjnkppdmfmijf", 1, False
End If
