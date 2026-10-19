#!/usr/bin/env python3
"""A pseudo-terminal for the bridge's shell: Node has none without a native
module, Python has one in its standard library.

    terminal.py COLS ROWS PROGRAM [ARGS…]

PROGRAM runs on a new terminal of COLS × ROWS. Keystrokes come in on stdin and
the screen goes out on stdout; lines "COLS ROWS" on fd 3 resize the terminal
(the kernel tells the program with SIGWINCH). When stdin closes the program
gets SIGHUP, as when a terminal window closes; the exit code is the program's
(128 + the signal when a signal ended it).
"""
import fcntl
import os
import pty
import select
import signal
import struct
import sys
import termios

CONTROL = 3


def resize(fd, cols, rows):
    cols = max(2, min(int(cols), 500))
    rows = max(2, min(int(rows), 200))
    fcntl.ioctl(fd, termios.TIOCSWINSZ, struct.pack('HHHH', rows, cols, 0, 0))


def write_all(fd, data):
    while data:
        data = data[os.write(fd, data):]


def main():
    cols, rows, argv = sys.argv[1], sys.argv[2], sys.argv[3:]
    pid, master = pty.fork()
    if pid == 0:
        try:
            os.execvp(argv[0], argv)
        finally:
            os._exit(127)

    resize(master, cols, rows)
    watched = [master, 0, CONTROL]
    pending = b''
    while True:
        try:
            ready, _, _ = select.select(watched, [], [])
        except InterruptedError:
            continue
        if master in ready:
            try:
                data = os.read(master, 65536)
            except OSError:
                data = b''
            if not data:
                break
            write_all(1, data)
        if 0 in ready:
            data = os.read(0, 65536)
            if data:
                write_all(master, data)
            else:
                watched.remove(0)
                os.kill(pid, signal.SIGHUP)
        if CONTROL in ready:
            data = os.read(CONTROL, 1024)
            if not data:
                watched.remove(CONTROL)
            pending += data
            while b'\n' in pending:
                line, pending = pending.split(b'\n', 1)
                try:
                    resize(master, *line.split())
                except (TypeError, ValueError):
                    pass

    _, status = os.waitpid(pid, 0)
    sys.exit(os.WEXITSTATUS(status) if os.WIFEXITED(status) else 128 + os.WTERMSIG(status))


if __name__ == '__main__':
    main()
