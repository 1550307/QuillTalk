(function (global) {
    'use strict';

    var FILES = 'abcdefg';
    var ALLOWED_PIECES = Object.freeze({
        wc: true,
        bc: true
    });

    function createInitialBoard() {
        return Array.from({ length: 6 }, function () {
            return Array(7).fill(null);
        });
    }

    function cloneBoard(board) {
        return Array.isArray(board) ? board.map(function (row) {
            return Array.isArray(row) ? row.slice(0, 7) : Array(7).fill(null);
        }).slice(0, 6) : createInitialBoard();
    }

    function normalizePiece(piece) {
        var normalized = String(piece || '').trim().toLowerCase();
        return ALLOWED_PIECES[normalized] ? normalized : null;
    }

    function pieceColor(piece) {
        return piece ? String(piece).charAt(0) : '';
    }

    function coordsToSquare(row, col) {
        if (row < 0 || row >= 6 || col < 0 || col >= 7) {
            return '';
        }
        return FILES.charAt(col) + String(6 - row);
    }

    function squareToCoords(square) {
        var normalized = String(square || '').trim().toLowerCase();
        if (!/^[a-g][1-6]$/.test(normalized)) {
            return null;
        }
        return {
            row: 6 - parseInt(normalized.charAt(1), 10),
            col: FILES.indexOf(normalized.charAt(0))
        };
    }

    function createInitialState() {
        return {
            version: 1,
            board: createInitialBoard(),
            turn: 'w',
            winnerColor: null,
            resultCode: null,
            resultLabel: null,
            lastMove: null,
            moveCount: 0
        };
    }

    function normalizeLastMove(value) {
        if (!value || typeof value !== 'object') {
            return null;
        }

        var from = String(value.from || '').trim().toLowerCase();
        var to = String(value.to || '').trim().toLowerCase();
        if (!/^[a-g][1-6]$/.test(from) || !/^[a-g][1-6]$/.test(to)) {
            return null;
        }

        return {
            from: from,
            to: to,
            piece: normalizePiece(value.piece),
            promotion: null,
            notation: String(value.notation || '').trim(),
            uci: String(value.uci || '').trim().toLowerCase(),
            playerColor: value.playerColor === 'b' ? 'b' : (value.playerColor === 'w' ? 'w' : null)
        };
    }

    function normalizeState(state) {
        if (!state || typeof state !== 'object') {
            return createInitialState();
        }

        var board = [];
        for (var row = 0; row < 6; row++) {
            var sourceRow = Array.isArray(state.board && state.board[row]) ? state.board[row] : [];
            var normalizedRow = [];
            for (var col = 0; col < 7; col++) {
                normalizedRow.push(normalizePiece(sourceRow[col]));
            }
            board.push(normalizedRow);
        }

        var winnerColor = state.winnerColor === 'b' ? 'b' : (state.winnerColor === 'w' ? 'w' : null);
        var resultCode = String(state.resultCode || '').trim() || null;
        var resultLabel = String(state.resultLabel || '').trim() || null;

        return {
            version: 1,
            board: board,
            turn: state.turn === 'b' ? 'b' : 'w',
            winnerColor: winnerColor,
            resultCode: resultCode,
            resultLabel: resultLabel,
            lastMove: normalizeLastMove(state.lastMove),
            moveCount: Math.max(0, Math.min(2000, Number(state.moveCount || 0) || 0))
        };
    }

    function cloneState(state) {
        return {
            version: 1,
            board: cloneBoard(state.board),
            turn: state.turn === 'b' ? 'b' : 'w',
            winnerColor: state.winnerColor === 'b' ? 'b' : (state.winnerColor === 'w' ? 'w' : null),
            resultCode: state.resultCode ? String(state.resultCode) : null,
            resultLabel: state.resultLabel ? String(state.resultLabel) : null,
            lastMove: state.lastMove ? {
                from: state.lastMove.from,
                to: state.lastMove.to,
                piece: state.lastMove.piece,
                promotion: null,
                notation: state.lastMove.notation,
                uci: state.lastMove.uci,
                playerColor: state.lastMove.playerColor
            } : null,
            moveCount: Math.max(0, Number(state.moveCount || 0) || 0)
        };
    }

    function getDropRow(board, col) {
        for (var row = 5; row >= 0; row--) {
            if (!board[row][col]) {
                return row;
            }
        }
        return -1;
    }

    function buildMove(color, row, col) {
        var square = coordsToSquare(row, col);
        return {
            from: square,
            to: square,
            capture: false,
            promotion: null,
            piece: color === 'b' ? 'bc' : 'wc',
            playerColor: color,
            notation: FILES.charAt(col).toUpperCase(),
            uci: square
        };
    }

    function getLegalMovesForColor(state, color) {
        var normalizedState = normalizeState(state);
        if ((normalizedState.winnerColor || normalizedState.resultCode) || normalizedState.turn !== color) {
            return [];
        }

        var moves = [];
        for (var col = 0; col < 7; col++) {
            var row = getDropRow(normalizedState.board, col);
            if (row >= 0) {
                moves.push(buildMove(color, row, col));
            }
        }
        return moves;
    }

    function getLegalMovesForSquare(state, square) {
        var normalizedState = normalizeState(state);
        var coords = squareToCoords(square);
        if (!coords) {
            return [];
        }

        var landingRow = getDropRow(normalizedState.board, coords.col);
        if (landingRow < 0) {
            return [];
        }

        return [buildMove(normalizedState.turn, landingRow, coords.col)];
    }

    function boardHasLine(board, color) {
        var piece = color === 'b' ? 'bc' : 'wc';
        var directions = [
            [0, 1],
            [1, 0],
            [1, 1],
            [1, -1]
        ];

        for (var row = 0; row < 6; row++) {
            for (var col = 0; col < 7; col++) {
                if (board[row][col] !== piece) {
                    continue;
                }
                for (var index = 0; index < directions.length; index++) {
                    var rowDelta = directions[index][0];
                    var colDelta = directions[index][1];
                    var streak = 1;
                    while (streak < 4) {
                        var nextRow = row + (rowDelta * streak);
                        var nextCol = col + (colDelta * streak);
                        if (nextRow < 0 || nextRow >= 6 || nextCol < 0 || nextCol >= 7) {
                            break;
                        }
                        if (board[nextRow][nextCol] !== piece) {
                            break;
                        }
                        streak++;
                    }
                    if (streak >= 4) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    function boardIsFull(board) {
        for (var col = 0; col < 7; col++) {
            if (getDropRow(board, col) >= 0) {
                return false;
            }
        }
        return true;
    }

    function attemptMove(state, from, to) {
        var normalizedState = normalizeState(state);
        if (normalizedState.winnerColor || normalizedState.resultCode) {
            return {
                ok: false,
                reason: 'This game is already finished.'
            };
        }

        var target = squareToCoords(String(to || from || '').trim().toLowerCase());
        if (!target) {
            return {
                ok: false,
                reason: 'Invalid connect four square.'
            };
        }

        var landingRow = getDropRow(normalizedState.board, target.col);
        if (landingRow < 0) {
            return {
                ok: false,
                reason: 'That column is already full.'
            };
        }

        var nextState = cloneState(normalizedState);
        var move = buildMove(normalizedState.turn, landingRow, target.col);
        nextState.board[landingRow][target.col] = move.piece;
        nextState.lastMove = move;
        nextState.moveCount = Math.max(0, Number(nextState.moveCount || 0) || 0) + 1;

        if (boardHasLine(nextState.board, normalizedState.turn)) {
            nextState.winnerColor = normalizedState.turn;
            nextState.resultCode = 'connect_four';
            nextState.resultLabel = 'Connected four';
        } else if (boardIsFull(nextState.board)) {
            nextState.winnerColor = null;
            nextState.resultCode = 'board_full';
            nextState.resultLabel = 'Board filled up';
        } else {
            nextState.turn = normalizedState.turn === 'b' ? 'w' : 'b';
        }

        return {
            ok: true,
            move: move,
            state: nextState
        };
    }

    global.QuillTalkConnectFour = {
        createInitialState: createInitialState,
        normalizeState: normalizeState,
        attemptMove: attemptMove,
        getLegalMovesForColor: getLegalMovesForColor,
        getLegalMovesForSquare: getLegalMovesForSquare,
        coordsToSquare: coordsToSquare,
        squareToCoords: squareToCoords,
        getPieceGlyph: function () {
            return 'O';
        },
        getBoardDimensions: function () {
            return { rows: 6, cols: 7 };
        }
    };
})(window);
