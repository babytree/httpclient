package main

import (
	"net/http"
	"time"
)

func main() {
	http.HandleFunc("/sleep", func(w http.ResponseWriter, _ *http.Request) {
		time.Sleep(time.Second)
		w.Write([]byte("返回值"))
	})
	http.ListenAndServe("127.0.0.1:18888", nil)
}
